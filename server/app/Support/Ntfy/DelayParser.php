<?php

namespace App\Support\Ntfy;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Parses an ntfy delay (X-Delay / X-At / X-In): a duration ("30m", "2h",
 * "1d", "1h30m"), a Unix timestamp or a date ("2026-12-24 18:00", "tomorrow 10am").
 */
class DelayParser
{
    public static function parse(mixed $value): ?CarbonImmutable
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $value = trim((string) $value);

        if (ctype_digit($value) && strlen($value) >= 9) {
            return CarbonImmutable::createFromTimestamp((int) $value);
        }

        if (preg_match_all('/(\d+)\s*(weeks?|w|days?|d|hours?|h|min|m|sec|s)(?![a-z])/i', $value, $matches, PREG_SET_ORDER)
            && preg_replace('/[\d\s]|s|sec|m|min|h|hours?|d|days?|w|weeks?/i', '', $value) === '') {
            $seconds = 0;

            foreach ($matches as [, $amount, $unit]) {
                $seconds += (int) $amount * match (strtolower($unit[0])) {
                    's' => 1,
                    'm' => 60,
                    'h' => 3600,
                    'd' => 86400,
                    'w' => 604800,
                };
            }

            return CarbonImmutable::now()->addSeconds($seconds);
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
