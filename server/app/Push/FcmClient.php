<?php

namespace App\Push;

use App\Models\Device;
use App\Support\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Firebase Cloud Messaging (HTTP v1 API) for Android devices, authenticated
 * with a service account JSON key configured from the administration.
 *
 * @see https://firebase.google.com/docs/cloud-messaging/send/v1-api
 */
class FcmClient
{
    public function __construct(protected Settings $settings) {}

    public function isConfigured(): bool
    {
        $account = $this->account();

        return isset($account['client_email'], $account['private_key'], $account['project_id']);
    }

    /**
     * @param  array<string, string>  $data  Data-only message: the app builds the notification.
     */
    public function send(Device $device, array $data, bool $highPriority, ?string $collapseKey = null): ApnsResult
    {
        $account = $this->account();

        if (! $this->isConfigured()) {
            throw new ApnsException('Firebase Cloud Messaging is not configured.');
        }

        try {
            $response = Http::withToken($this->accessToken())->acceptJson()->timeout(10)->post(
                'https://fcm.googleapis.com/v1/projects/'.$account['project_id'].'/messages:send',
                ['message' => array_filter([
                    'token' => $device->token,
                    'data' => array_map('strval', $data),
                    'android' => array_filter([
                        'priority' => $highPriority ? 'HIGH' : 'NORMAL',
                        'ttl' => '86400s',
                        'collapse_key' => $collapseKey,
                    ]),
                ])],
            );
        } catch (ConnectionException $e) {
            throw new ApnsException('Unable to reach Firebase: '.$e->getMessage(), previous: $e);
        }

        if ($response->status() === 401) {
            Cache::forget($this->cacheKey());
        }

        $errorCode = $response->json('error.details.0.errorCode') ?? $response->json('error.status');

        return new ApnsResult(
            $response->successful() ? 200 : ($errorCode === 'UNREGISTERED' ? 410 : $response->status()),
            $response->successful() ? null : ($errorCode === 'UNREGISTERED' ? 'Unregistered' : ($errorCode ?? 'HTTP '.$response->status())),
        );
    }

    /** OAuth access token obtained with a JWT signed by the service account (RS256). */
    public function accessToken(): string
    {
        return Cache::remember($this->cacheKey(), now()->addMinutes(50), function () {
            $account = $this->account();
            $now = time();
            $segments = [
                $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
                $this->base64Url(json_encode([
                    'iss' => $account['client_email'],
                    'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                    'aud' => $account['token_uri'] ?? 'https://oauth2.googleapis.com/token',
                    'iat' => $now,
                    'exp' => $now + 3600,
                ])),
            ];

            if (! openssl_sign(implode('.', $segments), $signature, $account['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new ApnsException('Invalid Firebase service account key.');
            }

            $segments[] = $this->base64Url($signature);

            $response = Http::asForm()->timeout(10)->post($account['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => implode('.', $segments),
            ]);

            if (! $response->successful() || ! $response->json('access_token')) {
                throw new ApnsException('Firebase refused the service account: '.($response->json('error_description') ?? $response->status()));
            }

            return $response->json('access_token');
        });
    }

    /** @return array<string, mixed> */
    protected function account(): array
    {
        return json_decode((string) $this->settings->get('fcm.service_account'), true) ?: [];
    }

    protected function cacheKey(): string
    {
        return 'fcm.token.'.sha1((string) $this->settings->get('fcm.service_account'));
    }

    protected function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
