<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Incoming webhook of a topic: a secret URL that turns the payload of another
 * tool (Grafana, GitHub, Uptime Kuma, Slack/Discord-compatible senders, ...)
 * into a message.
 */
#[Fillable(['topic_id', 'user_id', 'name', 'source', 'token'])]
class TopicWebhook extends Model
{
    public const SOURCES = ['auto', 'alertmanager', 'grafana', 'github', 'gitlab', 'uptime_kuma', 'slack', 'discord', 'generic'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (TopicWebhook $webhook) => $webhook->token ??= Str::random(40));
    }

    public function url(): string
    {
        return route('webhooks.receive', $this->token);
    }

    /** @return BelongsTo<Topic, $this> */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function sourceLabel(string $source): string
    {
        return match ($source) {
            'auto' => __('Automatic detection'),
            'alertmanager' => 'Prometheus Alertmanager',
            'grafana' => 'Grafana',
            'github' => 'GitHub',
            'gitlab' => 'GitLab',
            'uptime_kuma' => 'Uptime Kuma',
            'slack' => __('Slack-compatible (Coolify, …)'),
            'discord' => __('Discord-compatible'),
            default => __('Generic JSON or text'),
        };
    }
}
