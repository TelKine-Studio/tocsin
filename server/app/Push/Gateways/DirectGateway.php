<?php

namespace App\Push\Gateways;

use App\Push\ApnsClient;
use App\Push\ApnsException;
use App\Push\ApnsResult;
use App\Push\FcmClient;
use App\Push\PushGateway;
use App\Push\PushPayload;
use Illuminate\Support\Facades\Log;

/**
 * Sends notifications with this instance's own APNs key.
 */
class DirectGateway implements PushGateway
{
    public function __construct(protected ApnsClient $apns, protected FcmClient $fcm) {}

    public function isConfigured(): bool
    {
        return $this->apns->isConfigured() || $this->fcm->isConfigured();
    }

    public function send(iterable $devices, array $payload, array $headers = []): array
    {
        $results = [];

        foreach ($devices as $device) {
            try {
                $results[$device->id] = match ($device->platform) {
                    'android' => $this->fcm->send($device, PushPayload::forFcm($payload), ($headers['apns-priority'] ?? '10') === '10', $headers['apns-collapse-id'] ?? null),
                    default => $this->apns->send($device, $payload, $headers),
                };
            } catch (ApnsException $e) {
                Log::warning('APNs delivery failed', ['device' => $device->id, 'error' => $e->getMessage()]);
                $results[$device->id] = new ApnsResult(0, $e->getMessage());
            }
        }

        return $results;
    }
}
