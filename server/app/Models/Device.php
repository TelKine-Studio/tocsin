<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A mobile device registered for push notifications.
 * The APNs token is a secret: it is never exposed by the API or the UI.
 */
#[Fillable(['user_id', 'platform', 'environment', 'token', 'web_subscription', 'name', 'app_version', 'last_seen_at'])]
#[Hidden(['token', 'web_subscription'])]
class Device extends Model
{
    public const ENVIRONMENTS = ['sandbox', 'production'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'last_push_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<Topic, $this> */
    public function topics(): BelongsToMany
    {
        return $this->belongsToMany(Topic::class)->withTimestamps();
    }

    /**
     * Whether the device may still receive the messages of [topic]: access is
     * re-checked at send time, so revoked permissions take effect immediately.
     */
    public function canReceive(Topic $topic): bool
    {
        if ($topic->isPublic()) {
            return true;
        }

        return $this->user !== null && $this->user->is_active && $topic->canRead($this->user);
    }
}
