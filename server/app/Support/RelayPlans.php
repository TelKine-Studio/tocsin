<?php

namespace App\Support;

/**
 * Paid plans of the push relay, priced from Settings → Relay.
 */
class RelayPlans
{
    public const PAID = ['pro', 'business'];

    public function __construct(protected Settings $settings) {}

    /** @return array<string, array{name: string, daily_limit: int, price: int, currency: string}> */
    public function paid(): array
    {
        $plans = [];

        foreach (self::PAID as $plan) {
            $price = $this->settings->integer("relay_server.{$plan}_price");

            if ($price > 0) {
                $plans[$plan] = [
                    'name' => ucfirst($plan),
                    'daily_limit' => $this->dailyLimit($plan),
                    'price' => $price,
                    'currency' => $this->settings->string('relay_server.currency', 'XOF'),
                ];
            }
        }

        return $plans;
    }

    public function dailyLimit(string $plan): int
    {
        return match ($plan) {
            'pro', 'business' => $this->settings->integer("relay_server.{$plan}_daily_limit"),
            default => $this->settings->integer('relay_server.free_daily_limit'),
        };
    }

    /** @return list<string> Payment providers configured on this relay. */
    public function providers(): array
    {
        return array_values(array_filter([
            filled($this->settings->get('payments.cinetpay_site_id')) && filled($this->settings->get('payments.cinetpay_api_key')) ? 'cinetpay' : null,
            filled($this->settings->get('payments.stripe_secret')) ? 'stripe' : null,
        ]));
    }
}
