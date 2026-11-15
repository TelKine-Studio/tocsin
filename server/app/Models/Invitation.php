<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'role', 'token_hash', 'invited_by', 'expires_at'])]
class Invitation extends Model
{
    use Prunable;

    public const VALID_DAYS = 7;

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * Create an invitation and return it together with its plain token.
     * Only the SHA-256 hash of the token is stored.
     *
     * @return array{0: Invitation, 1: string}
     */
    public static function issue(string $email, Role $role, ?User $inviter, ?string $name = null): array
    {
        $token = Str::random(48);

        $invitation = static::create([
            'name' => $name,
            'email' => Str::lower($email),
            'role' => $role,
            'token_hash' => hash('sha256', $token),
            'invited_by' => $inviter?->id,
            'expires_at' => now()->addDays(self::VALID_DAYS),
        ]);

        return [$invitation, $token];
    }

    public static function findPendingByToken(string $token): ?self
    {
        return static::query()
            ->pending()
            ->where('token_hash', hash('sha256', $token))
            ->first();
    }

    /** @param Builder<Invitation> $query */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /** @return Builder<Invitation> */
    public function prunable(): Builder
    {
        return static::where('expires_at', '<', now()->subDays(30));
    }
}
