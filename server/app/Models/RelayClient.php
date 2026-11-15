<?php

namespace App\Models;

use App\Support\Settings;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * A Tocsin instance that sends its notifications through this relay.
 */
#[Fillable(['name', 'url', 'email', 'plan', 'daily_limit', 'is_active', 'registered_ip'])]
#[Hidden(['key_hash'])]
class RelayClient extends Model
{
    public const PLANS = ['free', 'pro', 'business'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'daily_limit' => 'integer',
            'last_used_at' => 'datetime',
            'plan_expires_at' => 'datetime',
        ];
    }

    /**
     * Creates a client and returns it with its plain key (shown only once).
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: RelayClient, 1: string}
     */
    public static function register(array $attributes): array
    {
        $client = new static($attributes);
        $key = $client->rotateKey();

        return [$client, $key];
    }

    /** Generates a new key, saves the client and returns the plain key. */
    public function rotateKey(): string
    {
        $key = 'tcr_'.Str::random(40);

        $this->forceFill([
            'key_hash' => hash('sha256', $key),
            'key_prefix' => substr($key, 0, 10),
        ])->save();

        return $key;
    }

    public static function findByKey(string $key): ?self
    {
        return static::query()->where('key_hash', hash('sha256', $key))->first();
    }

    /** @return HasMany<RelayPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(RelayPayment::class);
    }

    /** @return HasMany<RelayUsage, $this> */
    public function usages(): HasMany
    {
        return $this->hasMany(RelayUsage::class);
    }

    /** @return HasOne<RelayUsage, $this> */
    public function todayUsage(): HasOne
    {
        return $this->hasOne(RelayUsage::class)->where('date', now()->toDateString());
    }

    /** Notifications allowed per day: client override, else the free plan default. */
    public function effectiveDailyLimit(): int
    {
        return $this->daily_limit ?? app(Settings::class)->integer('relay_server.free_daily_limit', 1000);
    }

    public function usedToday(): int
    {
        return (int) $this->usages()->where('date', now()->toDateString())->value('sent')
            + (int) $this->usages()->where('date', now()->toDateString())->value('failed');
    }
}
