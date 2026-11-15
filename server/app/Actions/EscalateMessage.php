<?php

namespace App\Actions;

use App\Events\MessageUpdated;
use App\Jobs\SendMessagePush;
use App\Models\Message;
use App\Models\User;
use App\Notifications\EscalationNotification;

class EscalateMessage
{
    /**
     * Runs the next escalation step of an unacknowledged message: every
     * subscriber is notified again and the people of the step receive an
     * email. Then the following step is scheduled, if any.
     */
    public function handle(Message $message): void
    {
        $steps = $message->topic->escalationSteps();
        $step = $steps[$message->escalation_level] ?? null;

        if ($step === null || ! $message->awaitsAcknowledgement()) {
            $message->forceFill(['next_escalation_at' => null])->save();

            return;
        }

        $level = $message->escalation_level + 1;
        $next = $steps[$level] ?? null;
        $start = $message->dispatched_at ?? now();

        $message->forceFill([
            'escalation_level' => $level,
            'next_escalation_at' => $next ? $start->copy()->addMinutes($next['after']) : null,
        ])->save();

        SendMessagePush::dispatch($message, SendMessagePush::REMINDER);

        if ($step['users'] !== []) {
            User::query()
                ->whereKey($step['users'])
                ->where('is_active', true)
                ->whereNotNull('email')
                ->get()
                ->filter(fn (User $user) => $message->topic->canRead($user))
                ->each(fn (User $user) => $user->notify(new EscalationNotification($message)));
        }

        MessageUpdated::safelyBroadcast($message);
    }
}
