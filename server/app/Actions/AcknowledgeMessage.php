<?php

namespace App\Actions;

use App\Events\MessageUpdated;
use App\Jobs\SendMessagePush;
use App\Models\Message;
use App\Models\User;

class AcknowledgeMessage
{
    /**
     * Marks the message as handled: reminders stop and the notification
     * is replaced on the other subscribed devices by "Acknowledged by …".
     * The devices of whoever acknowledged (their account, or the device
     * given by its push token) are left out: they already know.
     *
     * Messages of the same acknowledgement group (X-Ack-Group: one event
     * published in several topics, possibly of different accounts) are
     * acknowledged with it, and their subscribers told the same way.
     *
     * Returns false when the message was already acknowledged.
     */
    public function handle(Message $message, ?User $user, ?string $name = null, ?string $deviceToken = null): bool
    {
        if ($message->isAcknowledged()) {
            return false;
        }

        $this->acknowledge($message, $user, $name, $deviceToken);

        foreach ($this->siblings($message) as $sibling) {
            $this->acknowledge($sibling, $user, $name, $deviceToken);
        }

        return true;
    }

    protected function acknowledge(Message $message, ?User $user, ?string $name, ?string $deviceToken): void
    {
        $message->forceFill([
            'acknowledged_at' => now(),
            'acknowledged_by' => $user?->id,
            'acknowledged_name' => $user ? null : ($name !== null ? mb_substr(trim($name), 0, 100) ?: null : null),
            'next_escalation_at' => null,
        ])->save();

        $message->setRelation('acknowledger', $user);

        if ($message->requires_ack) {
            SendMessagePush::dispatch($message, SendMessagePush::ACKNOWLEDGED, $this->acknowledgerDevices($message, $user, $deviceToken));
        }

        MessageUpdated::safelyBroadcast($message);
    }

    /**
     * Messages still waiting for an acknowledgement in the group of
     * [message], published recently.
     *
     * @return iterable<Message>
     */
    protected function siblings(Message $message): iterable
    {
        if ($message->ack_group === null) {
            return [];
        }

        return Message::query()
            ->with('topic')
            ->where('ack_group', $message->ack_group)
            ->whereKeyNot($message->id)
            ->where('requires_ack', true)
            ->whereNull('acknowledged_at')
            ->whereNotNull('dispatched_at')
            ->where('created_at', '>=', now()->subDays(Message::ACK_GROUP_WINDOW_DAYS))
            ->get();
    }

    /**
     * Devices that must not be told about the acknowledgement.
     *
     * @return list<int>
     */
    protected function acknowledgerDevices(Message $message, ?User $user, ?string $deviceToken): array
    {
        $deviceToken = is_string($deviceToken) ? trim($deviceToken) : '';

        if ($user === null && $deviceToken === '') {
            return [];
        }

        return $message->topic->devices()
            ->where(function ($query) use ($user, $deviceToken) {
                if ($user !== null) {
                    $query->orWhere('devices.user_id', $user->id);
                }

                if ($deviceToken !== '') {
                    $query->orWhere('devices.token', $deviceToken);
                }
            })
            ->pluck('devices.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
