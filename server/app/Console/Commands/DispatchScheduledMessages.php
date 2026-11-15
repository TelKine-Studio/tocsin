<?php

namespace App\Console\Commands;

use App\Actions\PublishMessage;
use App\Models\Message;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tocsin:dispatch-scheduled')]
#[Description('Deliver scheduled messages whose time has come')]
class DispatchScheduledMessages extends Command
{
    public function handle(PublishMessage $publisher): int
    {
        $count = 0;

        Message::query()
            ->whereNull('dispatched_at')
            ->where('scheduled_at', '<=', now())
            ->with('topic')
            ->lazyById()
            ->each(function (Message $message) use ($publisher, &$count) {
                $publisher->deliver($message);
                $count++;
            });

        if ($count > 0) {
            $this->components->info("{$count} scheduled message(s) delivered.");
        }

        return self::SUCCESS;
    }
}
