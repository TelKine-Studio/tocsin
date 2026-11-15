<?php

namespace App\Models;

use App\Support\RelayPlans;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Fillable(['relay_client_id', 'reference', 'plan', 'amount', 'currency', 'provider', 'provider_reference', 'status', 'period_days'])]
class RelayPayment extends Model
{
    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'amount' => 'integer', 'period_days' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(fn (RelayPayment $payment) => $payment->reference ??= 'TCS'.Str::upper(Str::random(17)));
    }

    /** @return BelongsTo<RelayClient, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(RelayClient::class, 'relay_client_id');
    }

    /**
     * Marks the payment as paid (once) and extends the client's plan.
     */
    public function markPaid(?string $providerReference = null): void
    {
        DB::transaction(function () use ($providerReference) {
            $payment = static::query()->lockForUpdate()->find($this->id);

            if ($payment->status === 'paid') {
                return;
            }

            $payment->forceFill(['status' => 'paid', 'paid_at' => now(), 'provider_reference' => $providerReference ?? $payment->provider_reference])->save();

            $client = $payment->client;
            $plans = app(RelayPlans::class);
            $start = $client->plan === $payment->plan && $client->plan_expires_at?->isFuture() ? $client->plan_expires_at : now();

            $client->forceFill([
                'plan' => $payment->plan,
                'daily_limit' => $plans->dailyLimit($payment->plan),
                'plan_expires_at' => $start->copy()->addDays($payment->period_days),
            ])->save();
        });

        $this->refresh();
    }
}
