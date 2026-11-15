<?php

namespace App\Console\Commands;

use App\Models\RelayClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tocsin:expire-relay-plans')]
#[Description('Move relay instances whose paid plan has expired back to the free plan')]
class ExpireRelayPlans extends Command
{
    public function handle(): int
    {
        $count = RelayClient::query()
            ->whereNotNull('plan_expires_at')
            ->where('plan_expires_at', '<', now())
            ->update(['plan' => 'free', 'daily_limit' => null, 'plan_expires_at' => null]);

        $this->components->info("{$count} plan(s) expired.");

        return self::SUCCESS;
    }
}
