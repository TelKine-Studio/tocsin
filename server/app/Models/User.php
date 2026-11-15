<?php

namespace App\Models;

use App\Enums\Role;
use App\Support\RelayPlans;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'is_active', 'locale', 'plan', 'daily_limit', 'plan_expires_at'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'daily_limit' => 'integer',
            'plan_expires_at' => 'datetime',
        ];
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    /** @return HasMany<Topic, $this> */
    public function ownedTopics(): HasMany
    {
        return $this->hasMany(Topic::class, 'owner_id');
    }

    /** @return BelongsToMany<Topic, $this> */
    public function topics(): BelongsToMany
    {
        return $this->belongsToMany(Topic::class)
            ->withPivot(['can_read', 'can_write'])
            ->withTimestamps();
    }

    /** @return HasMany<RelayPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(RelayPayment::class);
    }

    /** @return HasMany<AccountUsage, $this> */
    public function usages(): HasMany
    {
        return $this->hasMany(AccountUsage::class);
    }

    /** Messages this account may publish per day (hosted mode). */
    public function effectiveDailyLimit(): int
    {
        return $this->daily_limit ?? app(RelayPlans::class)->dailyLimit($this->plan ?? 'free');
    }

    public function messagesToday(): int
    {
        return (int) $this->usages()->where('date', now()->toDateString())->value('messages');
    }

    /** Topics this account may own; null = unlimited. */
    public function maxTopics(): ?int
    {
        return app(RelayPlans::class)->maxTopics($this->plan ?? 'free');
    }

    public function isOnPaidPlan(): bool
    {
        return in_array($this->plan, RelayPlans::PAID, true);
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function initials(): string
    {
        return collect(preg_split('/\s+/', trim($this->name)))
            ->filter()
            ->take(2)
            ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    }
}
