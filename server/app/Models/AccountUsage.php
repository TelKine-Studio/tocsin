<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Messages published per account and per day (hosted mode). */
#[Fillable(['user_id', 'date', 'messages', 'rejected', 'warned'])]
class AccountUsage extends Model
{
    public $timestamps = false;

    // "date" is a plain Y-m-d string, like RelayUsage.

    protected function casts(): array
    {
        return ['messages' => 'integer', 'rejected' => 'integer', 'warned' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function today(User $user): self
    {
        return static::query()->firstOrCreate(['user_id' => $user->id, 'date' => now()->toDateString()]);
    }
}
