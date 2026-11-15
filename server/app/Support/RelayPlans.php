<?php

namespace App\Support;

/**
 * Free, Pro and Business plans, priced in Settings → Plans. The same grid
 * applies to relay instances and, in hosted mode, to accounts: the daily
 * limit counts notifications for an instance, published messages for an
 * account.
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

    /** Topics an account of this plan can own; null = unlimited. */
    public function maxTopics(string $plan): ?int
    {
        if (in_array($plan, self::PAID, true)) {
            return null;
        }

        $max = $this->settings->integer('hosted.free_max_topics');

        return $max > 0 ? $max : null;
    }

    /** Whether paid plans can be bought here (prices and a provider set). */
    public function sellable(): bool
    {
        return $this->paid() !== [] && $this->providers() !== [];
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
