<?php

namespace App\Enums;

enum Priority: int
{
    case Min = 1;
    case Low = 2;
    case Default = 3;
    case High = 4;
    case Urgent = 5;

    public function label(): string
    {
        return match ($this) {
            self::Min => __('Min'),
            self::Low => __('Low'),
            self::Default => __('Default'),
            self::High => __('High'),
            self::Urgent => __('Urgent'),
        };
    }

    /**
     * Parse a priority from user input: a number (1-5) or a name
     * (min, low, default, high, urgent/max), as accepted by ntfy.
     */
    public static function parse(mixed $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return self::tryFrom((int) $value);
        }

        return match (strtolower(trim((string) $value))) {
            'min' => self::Min,
            'low' => self::Low,
            'default', 'normal' => self::Default,
            'high' => self::High,
            'urgent', 'max' => self::Urgent,
            default => null,
        };
    }
}
