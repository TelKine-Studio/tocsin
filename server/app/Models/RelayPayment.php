<?php

namespace App\Models;

use App\Support\RelayPlans;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Fillable(['relay_client_id', 'user_id', 'reference', 'plan', 'amount', 'currency', 'provider', 'provider_reference', 'status', 'period_days'])]
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Relay instance or account that pays. */
    public function payer(): RelayClient|User|null
    {
        return $this->user_id ? $this->user : $this->client;
    }

    /** Name shown on the payment page of the provider. */
    public function description(): string
    {
        return $this->user_id
            ? 'Tocsin '.ucfirst($this->plan).' - '.$this->user?->email
            : 'Tocsin relay '.ucfirst($this->plan).' - '.$this->client?->name;
    }

    /**
     * Marks the payment as paid (once) and extends the plan of the relay
     * instance or of the account.
     */
    public function markPaid(?string $providerReference = null): void
    {
        DB::transaction(function () use ($providerReference) {
            $payment = static::query()->lockForUpdate()->find($this->id);

            if ($payment->status === 'paid') {
                return;
            }

            $payment->forceFill(['status' => 'paid', 'paid_at' => now(), 'provider_reference' => $providerReference ?? $payment->provider_reference])->save();

            $payer = $payment->payer();
            $start = $payer->plan === $payment->plan && $payer->plan_expires_at?->isFuture() ? $payer->plan_expires_at : now();

            $payer->forceFill([
                'plan' => $payment->plan,
                // Accounts follow the plan's limit; instances keep their own copy.
                'daily_limit' => $payer instanceof User ? null : app(RelayPlans::class)->dailyLimit($payment->plan),
                'plan_expires_at' => $start->copy()->addDays($payment->period_days),
            ])->save();
        });

        $this->refresh();
    }
}
