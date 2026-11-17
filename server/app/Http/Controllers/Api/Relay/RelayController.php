<?php

namespace App\Http\Controllers\Api\Relay;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\RelayClient;
use App\Models\RelayToken;
use App\Models\RelayUsage;
use App\Push\ApnsClient;
use App\Push\ApnsException;
use App\Push\FcmClient;
use App\Push\PushPayload;
use App\Support\RelayPlans;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

/**
 * Push relay: lets Tocsin instances without their own Apple Push key deliver
 * notifications to the official app. Content is forwarded, never stored;
 * only daily counters are kept for quotas.
 */
class RelayController extends Controller
{
    public function __construct(protected Settings $settings) {}

    /** Self-service registration of an instance (free plan). */
    public function register(Request $request): JsonResponse
    {
        abort_unless($this->settings->relayServerActive(), 404);

        if (! $this->settings->boolean('relay_server.registration')) {
            return response()->json(['error' => 'registration_closed', 'message' => 'This relay does not accept new instances. Contact its operator.'], 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'url' => ['required', 'url:http,https', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        [$client, $key] = RelayClient::register([...$data, 'plan' => 'free', 'registered_ip' => $request->ip()]);

        return response()->json(['data' => [
            'key' => $key,
            ...$this->usagePayload($client),
        ]], 201);
    }

    public function usage(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->usagePayload($request->attributes->get('relay_client'))]);
    }

    public function push(Request $request, ApnsClient $apns, FcmClient $fcm): JsonResponse
    {
        /** @var RelayClient $client */
        $client = $request->attributes->get('relay_client');

        if (! $apns->isConfigured() && ! $fcm->isConfigured()) {
            return response()->json(['error' => 'unavailable', 'message' => 'The relay has no push credentials configured.'], 503);
        }

        $data = $request->validate([
            'notifications' => ['required', 'array', 'min:1', 'max:100'],
            'notifications.*.ref' => ['required', 'string', 'max:64'],
            'notifications.*.token' => ['required', 'string', 'max:500'],
            'notifications.*.platform' => ['nullable', Rule::in(['ios', 'android'])],
            'notifications.*.environment' => ['required', Rule::in(Device::ENVIRONMENTS)],
            'notifications.*.payload' => ['required', 'array'],
            'notifications.*.payload.aps' => ['required', 'array'],
            'notifications.*.headers' => ['nullable', 'array'],
        ]);

        $notifications = collect($data['notifications']);

        // Burst protection (per minute) and daily quota.
        $burstKey = 'relay-burst:'.$client->id;
        $burst = $this->settings->integer('relay_server.burst_per_minute', 600);

        if (RateLimiter::tooManyAttempts($burstKey, $burst)) {
            return response()->json(['error' => 'rate_limited', 'message' => 'Too many notifications per minute.', 'retry_after' => RateLimiter::availableIn($burstKey)], 429);
        }

        // Devices bound to too many other instances are refused (see RelayToken).
        $hashes = $notifications->mapWithKeys(fn ($n, $i) => [$i => RelayToken::hash($n['platform'] ?? 'ios', $n['token'])]);
        $refused = RelayToken::claim($client, $hashes->values()->all())->flip();

        // The quota is reserved under a lock, so parallel requests cannot all
        // see the same remaining count and go over it together.
        $allowed = $notifications->keys()->reject(fn ($i) => isset($refused[$hashes[$i]]))->values();
        $inFlightKey = 'relay-in-flight:'.$client->id.':'.now()->toDateString();
        $reserved = Cache::lock('relay-quota:'.$client->id, 10)->block(10, function () use ($client, $allowed, $inFlightKey) {
            $remaining = max(0, $client->effectiveDailyLimit() - $client->usedToday() - (int) Cache::get($inFlightKey, 0));
            $reserved = min($remaining, $allowed->count());
            if ($reserved > 0) {
                Cache::add($inFlightKey, 0, now()->addDay());
                Cache::increment($inFlightKey, $reserved);
            }

            return $reserved;
        });

        if ($reserved === 0 && $allowed->isNotEmpty()) {
            RelayUsage::record($client, 0, 0, $notifications->count());

            return response()->json(['error' => 'quota_exceeded', 'message' => 'Daily quota reached.', 'usage' => $this->usagePayload($client)], 429);
        }

        $sendable = $allowed->take($reserved)->flip();
        $results = [];
        $sent = $failed = $rejected = 0;

        try {
            foreach ($notifications as $index => $notification) {
                if (isset($refused[$hashes[$index]])) {
                    $results[] = ['ref' => $notification['ref'], 'status' => 403, 'reason' => 'RelayTokenNotAllowed'];
                    $rejected++;

                    continue;
                }
                if (! isset($sendable[$index])) {
                    $results[] = ['ref' => $notification['ref'], 'status' => 429, 'reason' => 'RelayQuotaExceeded'];
                    $rejected++;

                    continue;
                }

                RateLimiter::hit($burstKey, 60);

                $platform = $notification['platform'] ?? 'ios';
                $device = new Device([
                    'platform' => $platform,
                    'token' => $platform === 'ios' ? strtolower($notification['token']) : $notification['token'],
                    'environment' => $notification['environment'],
                ]);

                try {
                    $payload = $this->sanitizePayload($notification['payload']);
                    $headers = $this->sanitizeHeaders($notification['headers'] ?? []);
                    $result = $platform === 'android'
                        ? $fcm->send($device, PushPayload::forFcm($payload), ($headers['apns-priority'] ?? '10') === '10', $headers['apns-collapse-id'] ?? null)
                        : $apns->send($device, $payload, $headers);
                    $results[] = ['ref' => $notification['ref'], 'status' => $result->status, 'reason' => $result->reason];
                    $result->successful() ? $sent++ : $failed++;
                } catch (ApnsException $e) {
                    $results[] = ['ref' => $notification['ref'], 'status' => 502, 'reason' => 'RelayUpstreamError'];
                    $failed++;
                }
            }
        } finally {
            RelayUsage::record($client, $sent, $failed, $rejected);
            if ($reserved > 0) {
                Cache::decrement($inFlightKey, $reserved);
            }
        }

        $client->forceFill(['last_used_at' => now()])->saveQuietly();

        return response()->json([
            'results' => $results,
            'usage' => $this->usagePayload($client),
        ]);
    }

    /** @return array<string, mixed> */
    protected function usagePayload(RelayClient $client): array
    {
        $plans = app(RelayPlans::class);

        return [
            'plan' => $client->plan,
            'plan_expires_at' => $client->plan_expires_at?->toIso8601String(),
            'daily_limit' => $client->effectiveDailyLimit(),
            'used_today' => $client->usedToday(),
            'bundle_id' => $this->settings->string('apns.bundle_id'),
            'plans' => $plans->paid(),
            'payment_providers' => $plans->providers(),
        ];
    }

    /**
     * Only the standard "aps" dictionary and Tocsin's own keys are forwarded.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function sanitizePayload(array $payload): array
    {
        $payload = array_intersect_key($payload, array_flip(['aps', 'tocsin']));

        abort_if(strlen((string) json_encode($payload)) > 4000, 422, 'Payload too large.');

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    protected function sanitizeHeaders(array $headers): array
    {
        return collect($headers)
            ->only(['apns-priority', 'apns-expiration', 'apns-collapse-id'])
            ->map(fn ($value) => (string) $value)
            ->all();
    }
}
