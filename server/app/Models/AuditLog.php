<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['user_id', 'action', 'subject_type', 'subject_id', 'description', 'metadata', 'ip_address', 'user_agent'])]
class AuditLog extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    /** Entries are kept one year. */
    public const RETENTION_DAYS = 365;

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return Builder<AuditLog> */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
