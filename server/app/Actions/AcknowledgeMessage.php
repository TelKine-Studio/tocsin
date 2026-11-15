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
     * is replaced on every subscribed device by "Acknowledged by …".
     *
     * Returns false when the message was already acknowledged.
     */
    public function handle(Message $message, ?User $user, ?string $name = null): bool
    {
        if ($message->isAcknowledged()) {
            return false;
        }

        $message->forceFill([
            'acknowledged_at' => now(),
            'acknowledged_by' => $user?->id,
            'acknowledged_name' => $user ? null : ($name !== null ? mb_substr(trim($name), 0, 100) ?: null : null),
            'next_escalation_at' => null,
        ])->save();

        $message->setRelation('acknowledger', $user);

        if ($message->requires_ack) {
            SendMessagePush::dispatch($message, SendMessagePush::ACKNOWLEDGED);
        }

        MessageUpdated::safelyBroadcast($message);

        return true;
    }
}
