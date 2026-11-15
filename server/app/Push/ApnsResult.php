<?php

namespace App\Push;

/**
 * Outcome of one APNs request.
 */
final readonly class ApnsResult
{
    /** Reasons for which the device token will never work again. */
    private const INVALID_TOKEN_REASONS = ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic', 'ExpiredToken'];

    public function __construct(
        public int $status,
        public ?string $reason = null,
    ) {}

    public function successful(): bool
    {
        return $this->status === 200;
    }

    /** The device should be forgotten (app uninstalled, token rotated, ...). */
    public function tokenIsInvalid(): bool
    {
        return $this->status === 410 || in_array($this->reason, self::INVALID_TOKEN_REASONS, true);
    }
}
