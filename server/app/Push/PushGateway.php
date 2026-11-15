<?php

namespace App\Push;

use App\Models\Device;

/**
 * Delivers notifications to devices, either directly to Apple Push
 * Notification service or through a Tocsin relay.
 */
interface PushGateway
{
    public function isConfigured(): bool;

    /**
     * @param  iterable<Device>  $devices
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     * @return array<int, ApnsResult> Results indexed by device id.
     *
     * @throws ApnsException When the gateway cannot be reached at all.
     */
    public function send(iterable $devices, array $payload, array $headers = []): array;
}
