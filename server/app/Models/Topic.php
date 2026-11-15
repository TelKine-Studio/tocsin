<?php

namespace App\Models;

use App\Enums\TopicVisibility;
use Database\Factories\TopicFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;

#[Fillable(['name', 'display_name', 'description', 'visibility', 'anonymous_publish', 'owner_id', 'group_window', 'ack_priority', 'escalation'])]
class Topic extends Model
{
    /** @use HasFactory<TopicFactory> */
    use HasFactory;

    /** Allowed topic names: lowercase letters, digits, dashes and underscores. */
    public const NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    protected function casts(): array
    {
        return [
            'visibility' => TopicVisibility::class,
            'anonymous_publish' => 'boolean',
            'last_message_at' => 'datetime',
            'group_window' => 'integer',
            'ack_priority' => 'integer',
            'escalation' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'name';
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['can_read', 'can_write'])
            ->withTimestamps();
    }

    /** @return BelongsToMany<Device, $this> */
    public function devices(): BelongsToMany
    {
        return $this->belongsToMany(Device::class)->withTimestamps();
    }

    /** @return HasMany<TopicWebhook, $this> */
    public function webhooks(): HasMany
    {
        return $this->hasMany(TopicWebhook::class);
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    protected static function booted(): void
    {
        // Messages are removed by the database (cascade): clean their files.
        static::deleting(function (Topic $topic) {
            Storage::disk('local')->deleteDirectory('attachments/'.$topic->id);

            if ($topic->icon_path) {
                Storage::disk('local')->delete($topic->icon_path);
            }
        });
    }

    /** Accepted logo formats (no SVG: it could run scripts). */
    public const ICON_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    public const ICON_MAX_KB = 1024;

    /**
     * Public URL of the logo. It must be reachable without an account: the
     * notification extensions of the apps download it. The version makes
     * caches refresh when the logo changes.
     */
    public function iconUrl(): ?string
    {
        if (! $this->icon_path) {
            return null;
        }

        return route('topics.icon', ['topic' => $this->name, 'v' => substr(md5($this->icon_path), 0, 8)]);
    }

    /** Logo of a message: its own X-Icon, otherwise the topic's. */
    public function iconFor(?Message $message): ?string
    {
        return $message?->icon_url ?: $this->iconUrl();
    }

    /** Whether a message of this priority must be acknowledged. */
    public function requiresAcknowledgement(int $priority): bool
    {
        return $this->ack_priority !== null && $this->ack_priority > 0 && $priority >= $this->ack_priority;
    }

    /**
     * Reminder steps of unacknowledged messages, sorted by delay.
     *
     * @return list<array{after: int, users: list<int>}>
     */
    public function escalationSteps(): array
    {
        return collect($this->escalation ?? [])
            ->filter(fn ($step) => is_array($step) && (int) ($step['after'] ?? 0) > 0)
            ->map(fn (array $step) => ['after' => (int) $step['after'], 'users' => array_values(array_map('intval', (array) ($step['users'] ?? [])))])
            ->sortBy('after')
            ->values()
            ->all();
    }

    public function isPublic(): bool
    {
        return $this->visibility === TopicVisibility::Public;
    }

    public function label(): string
    {
        return $this->display_name ?: $this->name;
    }

    /** Name of the realtime channel, without the "private-" prefix. */
    public function channelName(): string
    {
        return 'topic.'.$this->name;
    }

    /** Full channel name as seen by Pusher-protocol clients. */
    public function clientChannelName(): string
    {
        return ($this->isPublic() ? '' : 'private-').$this->channelName();
    }

    public function isManagedBy(?User $user): bool
    {
        return $user !== null && ($user->isAdmin() || $this->owner_id === $user->id);
    }

    /**
     * A personal access token limited to some topics ("topic:{name}"
     * abilities) only grants access to those topics.
     */
    protected function tokenAllows(?User $user): bool
    {
        $token = $user?->currentAccessToken();

        return ! $token instanceof PersonalAccessToken || $token->can('topic:'.$this->name);
    }

    public function canRead(?User $user): bool
    {
        if (! $this->tokenAllows($user)) {
            return false;
        }

        if ($this->isPublic() || $this->isManagedBy($user)) {
            return true;
        }

        return (bool) $this->membership($user)?->can_read;
    }

    public function canWrite(?User $user): bool
    {
        if (! $this->tokenAllows($user)) {
            return false;
        }

        if ($this->isManagedBy($user)) {
            return true;
        }

        if ($user === null) {
            return $this->isPublic() && $this->anonymous_publish;
        }

        if ($this->isPublic() && $this->anonymous_publish) {
            return true;
        }

        return (bool) $this->membership($user)?->can_write;
    }

    /** @return object{can_read: bool, can_write: bool}|null */
    protected function membership(?User $user): ?object
    {
        if ($user === null) {
            return null;
        }

        $member = $this->relationLoaded('members')
            ? $this->members->firstWhere('id', $user->id)
            : $this->members()->whereKey($user->id)->first();

        return $member?->pivot;
    }

    /**
     * Topics the given user (or a guest) is allowed to subscribe to.
     *
     * @param  Builder<Topic>  $query
     */
    public function scopeReadableBy(Builder $query, ?User $user): void
    {
        if ($user?->isAdmin()) {
            return;
        }

        $query->where(function (Builder $query) use ($user) {
            $query->where('visibility', TopicVisibility::Public->value);

            if ($user !== null) {
                $query->orWhere('owner_id', $user->id)
                    ->orWhereHas('members', fn (Builder $q) => $q
                        ->where('users.id', $user->id)
                        ->where('topic_user.can_read', true));
            }
        });
    }
}
