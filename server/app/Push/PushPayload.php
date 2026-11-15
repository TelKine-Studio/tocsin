<?php

namespace App\Push;

use App\Jobs\SendMessagePush;
use App\Models\Message;
use App\Support\Settings;
use Illuminate\Support\Str;

/**
 * Builds the APNs payload of a message.
 *
 * The app's Notification Service Extension (mutable-content) downloads the
 * image attachment, adds the action buttons and, in confidential mode,
 * fetches the content from this server: the payload then only carries the
 * topic and the message id, never the text.
 */
class PushPayload
{
    /** APNs refuses payloads larger than 4 KB. */
    protected const MAX_BODY_BYTES = 2500;

    /** @return array<string, mixed> */
    public static function forMessage(Message $message, string $serverUrl, ?string $notice = null): array
    {
        $topic = $message->topic;
        $acknowledged = $notice === SendMessagePush::ACKNOWLEDGED;
        $priority = $acknowledged ? 2 : $message->priority->value;
        $hasTitle = filled($message->title);
        $confidential = app(Settings::class)->boolean('push.confidential');
        $count = $message->occurrences > 1 ? '×'.$message->occurrences.' · ' : '';

        $alert = $confidential
            ? ['title' => $topic->label(), 'body' => $acknowledged ? __('Message acknowledged') : __('New message')]
            : array_filter([
                'title' => $hasTitle ? $message->title : $topic->label(),
                'subtitle' => $hasTitle ? $topic->label() : null,
                'body' => $acknowledged
                    ? '✓ '.$message->acknowledgementLabel()
                    : $count.Str::limit($message->body, self::MAX_BODY_BYTES),
            ]);

        if ($notice === SendMessagePush::REMINDER) {
            $alert['title'] = __('Reminder: :title', ['title' => $alert['title']]);
        }

        $attachment = $message->attachmentUrl();
        $isImage = $attachment && (str_starts_with((string) $message->attachment_type, 'image/') || preg_match('/\.(png|jpe?g|gif|webp|heic)$/i', (string) $message->attachment_name));
        $actions = $acknowledged ? [] : collect($message->actionsWithAcknowledgement())->map(fn (array $a) => array_filter([
            'action' => $a['action'],
            'label' => $a['label'],
            'url' => $a['url'],
            'method' => $a['method'] ?? null,
            'ack' => ($a['ack'] ?? false) ? true : null,
        ]))->values()->all();
        $isImage = $isImage && ! $acknowledged;

        return [
            'aps' => array_filter([
                'alert' => $alert,
                'sound' => $priority >= 3 ? 'default' : null,
                'thread-id' => $topic->name,
                'interruption-level' => match (true) {
                    $priority >= 5 => 'time-sensitive',
                    $priority <= 2 => 'passive',
                    default => 'active',
                },
                'relevance-score' => round($priority / 5, 2),
                'mutable-content' => ((! $acknowledged && $confidential) || $topic->iconFor($message) || $isImage || $actions) ? 1 : null,
            ], fn ($value) => $value !== null),
            'tocsin' => array_filter([
                'server' => rtrim($serverUrl, '/'),
                'topic' => $topic->name,
                'id' => $message->id,
                'priority' => $priority,
                'click' => $confidential ? null : $message->click_url,
                // Logo shown by the apps in the notification (topic or X-Icon).
                'icon' => $topic->iconFor($message),
                'image' => $isImage && ! $confidential ? $attachment : null,
                'actions' => $confidential ? null : ($actions ?: null),
                'fetch' => $confidential && ! $acknowledged ? 1 : null,
                'ack' => $message->awaitsAcknowledgement() && ! $acknowledged ? 1 : null,
            ], fn ($value) => $value !== null),
        ];
    }

    /**
     * Data-only FCM message: the Android app builds the notification itself
     * (channel by priority, big picture, action buttons).
     *
     * @return array<string, string>
     */
    public static function forFcm(array $apnsPayload): array
    {
        $alert = $apnsPayload['aps']['alert'] ?? [];
        $tocsin = $apnsPayload['tocsin'] ?? [];

        return array_map('strval', array_filter([
            'title' => $alert['title'] ?? null,
            'subtitle' => $alert['subtitle'] ?? null,
            'body' => $alert['body'] ?? null,
            'server' => $tocsin['server'] ?? null,
            'topic' => $tocsin['topic'] ?? null,
            'id' => $tocsin['id'] ?? null,
            'priority' => $tocsin['priority'] ?? 3,
            'click' => $tocsin['click'] ?? null,
            'image' => $tocsin['image'] ?? null,
            'icon' => $tocsin['icon'] ?? null,
            'fetch' => $tocsin['fetch'] ?? null,
            'actions' => isset($tocsin['actions']) ? json_encode($tocsin['actions']) : null,
            'ack' => $tocsin['ack'] ?? null,
        ], fn ($v) => $v !== null));
    }

    /** @return array<string, string> */
    public static function headers(Message $message, ?string $notice = null): array
    {
        return [
            // Low priority messages may be delivered in batches to save battery.
            'apns-priority' => ($notice === SendMessagePush::ACKNOWLEDGED || $message->priority->value <= 2) ? '5' : '10',
            'apns-expiration' => (string) now()->addDay()->getTimestamp(),
            // Same id for reminders and acknowledgements: they replace the
            // original notification instead of piling up.
            'apns-collapse-id' => Str::limit($message->topic->name.'-'.$message->id, 64, ''),
        ];
    }
}
