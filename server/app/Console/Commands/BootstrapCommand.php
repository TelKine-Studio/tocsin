<?php

namespace App\Console\Commands;

use App\Push\WebPushSender;
use App\Support\Settings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tocsin:bootstrap')]
#[Description('Prepare the instance: generate the realtime credentials if needed')]
class BootstrapCommand extends Command
{
    public function handle(Settings $settings): int
    {
        $settings->ensureRealtimeCredentials();
        app(WebPushSender::class)->ensureKeys();

        $this->components->info('Tocsin is ready.');

        return self::SUCCESS;
    }
}
