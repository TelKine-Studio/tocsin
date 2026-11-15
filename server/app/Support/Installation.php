<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\QueryException;

/**
 * Tracks whether the instance went through the first-run setup, i.e. whether
 * the first administrator account exists.
 */
class Installation
{
    protected static ?bool $installed = null;

    public static function isComplete(): bool
    {
        if (static::$installed === true) {
            return true;
        }

        try {
            return static::$installed = User::query()->exists();
        } catch (QueryException) {
            return false;
        }
    }

    public static function flush(): void
    {
        static::$installed = null;
    }
}
