<?php

namespace App\Push;

use App\Push\Gateways\DirectGateway;
use App\Push\Gateways\RelayGateway;
use App\Support\Settings;

/**
 * Returns the gateway selected in Settings → Push notifications.
 */
class PushManager
{
    public function __construct(protected Settings $settings) {}

    public function gateway(): PushGateway
    {
        return $this->settings->pushMode() === 'direct'
            ? app(DirectGateway::class)
            : app(RelayGateway::class);
    }
}
