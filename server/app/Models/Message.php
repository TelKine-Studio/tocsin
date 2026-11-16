<?php

namespace App\Models;

use App\Enums\Priority;
use App\Support\Settings;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

#[Fillable(['topic_id', 'user_id', 'title', 'body', 'priority', 'tags', 'click_url', 'actions', 'icon_url', 'attachment_url', 'attachment_name', 'attachment_type', 'attachment_size', 'attachment_path', 'scheduled_at', 'dispatched_at', 'push_status', 'push_failed', 'push_error', 'collapse_key', 'occurrences', 'repeats', 'last_seen_at', 'requires_ack', 'ack_group', 'acknowledged_at', 'acknowledged_by', 'acknowledged_name', 'escalation_level', 'next_escalation_at'])]
class Message extends Model
{
    /** Sends kept in the history of a grouped message. */
    public const MAX_REPEATS = 50;

    /** @use HasFactory<MessageFactory> */
    use HasFactory, Prunable;

    public const UPDATED_AT = null;

    /** Shortest accepted X-Ack-Group key: it must not be guessable. */
    public const ACK_GROUP_MIN_LENGTH = 16;

    public const ACK_GROUP_MAX_LENGTH = 200;

    /** Messages of an acknowledgement group older than this are left alone. */
    public const ACK_GROUP_WINDOW_DAYS = 7;

    /** "acknowledged_name" of alerts closed by their "resolved" notification. */
    public const RESOLVED = ':resolved';

    protected function casts(): array
    {
        return [
            'priority' => Priority::class,
            'tags' => 'array',
            'push_count' => 'integer',
            'actions' => 'array',
            'attachment_size' => 'integer',
            'scheduled_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'occurrences' => 'integer',
            'repeats' => 'array',
            'last_seen_at' => 'datetime',
            'requires_ack' => 'boolean',
            'acknowledged_at' => 'datetime',
            'escalation_level' => 'integer',
            'next_escalation_at' => 'datetime',
        ];
    }

    /**
     * Messages already delivered (scheduled ones wait for their time).
     *
     * @param  Builder<Message>  $query
     */
    public function scopeDelivered(Builder $query): void
    {
        $query->whereNotNull('dispatched_at');
    }

    public function isScheduled(): bool
    {
        return $this->dispatched_at === null;
    }

    /** Public URL of the attachment (signed when the file is stored here). */
    public function attachmentUrl(): ?string
    {
        if ($this->attachment_path) {
            return URL::signedRoute('attachments.show', ['message' => $this->id, 'name' => $this->attachment_name ?? 'file']);
        }

        return $this->attachment_url;
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledged_at !== null;
    }

    /** Waiting for someone to acknowledge it. */
    public function awaitsAcknowledgement(): bool
    {
        return $this->requires_ack && ! $this->isAcknowledged();
    }

    /** Name of who acknowledged the message ("Someone" for anonymous subscribers). */
    public function acknowledgedByName(): ?string
    {
        if (! $this->isAcknowledged()) {
            return null;
        }

        if ($this->acknowledged_by === null && $this->acknowledged_name === self::RESOLVED) {
            return null;
        }

        return $this->acknowledger?->name ?? $this->acknowledged_name ?? __('Someone');
    }

    /** Closed by the "resolved" notification of the alert. */
    public function wasResolved(): bool
    {
        return $this->isAcknowledged() && $this->acknowledged_by === null && $this->acknowledged_name === self::RESOLVED;
    }

    /** "Acknowledged by Jane" or "Resolved", for display. */
    public function acknowledgementLabel(): ?string
    {
        if (! $this->isAcknowledged()) {
            return null;
        }

        return $this->wasResolved()
            ? __('Resolved automatically')
            : __('Acknowledged by :name', ['name' => $this->acknowledgedByName()]);
    }

    /**
     * Signed URL that acknowledges the message. It is sent to subscribed
     * devices as an action button, so anonymous subscribers of a public
     * topic can acknowledge too; a token identifies signed-in users.
     */
    public function acknowledgeUrl(): string
    {
        return URL::signedRoute('messages.acknowledge', ['message' => $this->id]);
    }

    /**
     * The message actions, with the "Acknowledge" button first when an
     * acknowledgement is expected.
     *
     * @return list<array<string, mixed>>
     */
    public function actionsWithAcknowledgement(): array
    {
        $actions = $this->actions ?? [];

        if ($this->awaitsAcknowledgement()) {
            array_unshift($actions, ['action' => 'http', 'label' => __('Acknowledge'), 'url' => $this->acknowledgeUrl(), 'method' => 'POST', 'ack' => true]);
        }

        return array_slice($actions, 0, 3);
    }

    protected static function booted(): void
    {
        static::deleting(function (Message $message) {
            if ($message->attachment_path) {
                Storage::disk('local')->delete($message->attachment_path);
            }
        });
    }

    /** @return BelongsTo<Topic, $this> */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    /** @return BelongsTo<User, $this> */
    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Messages older than the configured retention period are removed
     * by the scheduler ("model:prune"). A retention of 0 keeps them forever.
     *
     * @return Builder<Message>
     */
    public function prunable(): Builder
    {
        $days = app(Settings::class)->integer('messages.retention_days');

        return $days > 0
            ? static::where('created_at', '<', now()->subDays($days))
            : static::whereRaw('1 = 0');
    }

    /**
     * Sends merged into this message, oldest first (the latest ones when
     * there were more than Message::MAX_REPEATS). Empty for a single send,
     * or for messages grouped before the history was kept.
     *
     * @return list<array{time: Carbon, title: ?string, body: ?string, priority: int}>
     */
    public function repeatLog(): array
    {
        return array_map(fn (array $repeat) => [
            'time' => Carbon::createFromTimestamp($repeat['at']),
            'title' => $repeat['title'] ?? null,
            'body' => $repeat['body'] ?? null,
            'priority' => (int) ($repeat['priority'] ?? 3),
        ], $this->occurrences > 1 ? ($this->repeats ?? []) : []);
    }
}
