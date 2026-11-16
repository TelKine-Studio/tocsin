<?php

namespace App\Jobs;

use App\Models\Device;
use App\Models\Message;
use App\Push\ApnsException;
use App\Push\PushManager;
use App\Push\PushPayload;
use App\Push\WebPushSender;
use App\Support\Settings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Sends a published message as a push notification to every device
 * subscribed to its topic.
 */
class SendMessagePush implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    /** Reminder of a message nobody acknowledged yet (escalation). */
    public const REMINDER = 'reminder';

    /** Replaces the notification with "Acknowledged by …". */
    public const ACKNOWLEDGED = 'acknowledged';

    /**
     * @param  list<int>  $except  Devices left out (e.g. those of whoever
     *                             acknowledged the message).
     */
    public function __construct(public Message $message, public ?string $notice = null, public array $except = [])
    {
        $this->afterCommit();
    }

    public function handle(PushManager $push, Settings $settings): void
    {
        $message = $this->message->loadMissing('topic');
        $topic = $message->topic;
        $gateway = $push->gateway();
        $hasWeb = $topic->devices()->where('platform', 'web')->whereNotIn('devices.id', $this->except)->exists();

        if (! $gateway->isConfigured() && ! $hasWeb) {
            $message->forceFill(['push_status' => 'disabled'])->save();

            return;
        }

        if ($this->notice === null) {
            $message->forceFill(['push_status' => 'pending'])->save();
        }

        $payload = PushPayload::forMessage($message, $settings->string('app.url') ?: (string) config('app.url'), $this->notice);
        $headers = PushPayload::headers($message, $this->notice);
        $sent = $failed = $quota = $targets = 0;
        $errors = [];

        $platforms = $gateway->isConfigured() ? ['ios', 'android', 'web'] : ['web'];
        $webSender = app(WebPushSender::class);
        $webNotification = [
            'title' => $payload['aps']['alert']['title'] ?? $topic->label(),
            'body' => $payload['aps']['alert']['body'] ?? __('New message'),
            // Same tag: reminders and acknowledgements replace the notification.
            'tag' => $topic->name.'-'.$message->id,
            'url' => route('topics.show', $topic),
            'icon' => $topic->iconFor($message) ?? asset('favicon.svg'),
            'priority' => $this->notice === self::ACKNOWLEDGED ? 2 : $message->priority->value,
            'renotify' => $this->notice !== self::ACKNOWLEDGED,
        ];

        $topic->devices()->with('user')->whereIn('platform', $platforms)
            ->when($this->except !== [], fn ($query) => $query->whereNotIn('devices.id', $this->except))
            ->lazyById(100, 'devices.id')
            ->filter(fn (Device $device) => $device->canReceive($topic))
            ->chunk(100)
            ->each(function ($devices) use ($gateway, $payload, $headers, &$sent, &$failed, &$quota, &$targets, &$errors) {
                $targets += count($devices);

                [$web, $mobile] = collect($devices)->partition(fn (Device $device) => $device->platform === 'web');

                try {
                    $results = ($mobile->isNotEmpty() ? $gateway->send($mobile, $payload, $headers) : [])
                        + ($web->isNotEmpty() ? $webSender->send($web, $webNotification) : []);
                } catch (ApnsException $e) {
                    Log::warning('Push delivery failed', ['error' => $e->getMessage()]);
                    $failed += count($devices);
                    $errors[] = $e->getMessage();

                    return;
                }

                foreach ($devices as $device) {
                    $result = $results[$device->id] ?? null;

                    if ($result?->successful()) {
                        $sent++;
                        $device->forceFill(['last_push_at' => now()])->saveQuietly();

                        continue;
                    }

                    $failed++;

                    if ($result?->reason === 'RelayQuotaExceeded') {
                        $quota++;
                    } elseif ($result?->tokenIsInvalid()) {
                        $device->delete();
                    } elseif ($result) {
                        $errors[] = $result->reason ?? 'HTTP '.$result->status;
                        Log::warning('Notification rejected', ['device' => $device->id, 'status' => $result->status, 'reason' => $result->reason]);
                    }
                }
            });

        // Reminders and acknowledgements keep the delivery status of the message.
        if ($this->notice !== null) {
            return;
        }

        $message->forceFill([
            'push_count' => $message->push_count + $sent,
            'push_failed' => $failed,
            'push_status' => match (true) {
                $targets === 0 => 'no_devices',
                $quota > 0 && $sent === 0 => 'quota',
                $failed === 0 => 'sent',
                $sent > 0 => 'partial',
                default => 'failed',
            },
            'push_error' => $errors ? mb_substr(implode(', ', array_unique($errors)), 0, 250) : null,
        ])->save();
    }
}
