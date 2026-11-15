<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;

/**
 * Public description of the instance, used by the mobile app to validate a
 * server URL and to discover how to reach the realtime (WebSocket) server.
 */
class InfoController extends Controller
{
    public function __invoke(Settings $settings): JsonResponse
    {
        return response()->json([
            'name' => $settings->string('app.name'),
            'version' => config('tocsin.version'),
            'registration_enabled' => $settings->boolean('registration.enabled'),
            'push' => [
                'apns' => $settings->pushConfigured(),
                'mode' => $settings->pushMode(),
                'bundle_id' => $settings->pushBundleId(),
            ],
            'realtime' => ['protocol' => 'pusher', ...$settings->realtimeClientConfig()],
        ]);
    }
}
