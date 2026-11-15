<?php

namespace App\Console\Commands;

use App\Actions\EscalateMessage;
use App\Models\Message;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tocsin:escalate')]
#[Description('Send reminders for messages nobody acknowledged')]
class EscalateMessages extends Command
{
    public function handle(EscalateMessage $escalate): int
    {
        $count = 0;

        Message::query()
            ->whereNotNull('next_escalation_at')
            ->where('next_escalation_at', '<=', now())
            ->whereNull('acknowledged_at')
            ->with('topic')
            ->lazyById()
            ->each(function (Message $message) use ($escalate, &$count) {
                $escalate->handle($message);
                $count++;
            });

        if ($count > 0) {
            $this->components->info("{$count} message(s) escalated.");
        }

        return self::SUCCESS;
    }
}
