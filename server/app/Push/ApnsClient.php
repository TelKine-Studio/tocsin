<?php

namespace App\Push;

use App\Models\Device;
use App\Support\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Minimal Apple Push Notification service client using token-based
 * authentication (.p8 key) over HTTP/2.
 *
 * @see https://developer.apple.com/documentation/usernotifications/sending-notification-requests-to-apns
 */
class ApnsClient
{
    public const HOSTS = [
        'production' => 'https://api.push.apple.com',
        'sandbox' => 'https://api.sandbox.push.apple.com',
    ];

    public function __construct(protected Settings $settings) {}

    public function isConfigured(): bool
    {
        return $this->settings->apnsConfigured();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers  Extra apns-* headers.
     */
    public function send(Device $device, array $payload, array $headers = []): ApnsResult
    {
        if (! $this->isConfigured()) {
            throw new ApnsException('APNs is not configured.');
        }

        $host = self::HOSTS[$device->environment] ?? self::HOSTS['production'];

        try {
            $response = Http::withOptions(['version' => 2.0])
                ->timeout(10)
                ->withHeaders([
                    'authorization' => 'bearer '.$this->token(),
                    'apns-topic' => $this->settings->string('apns.bundle_id'),
                    'apns-push-type' => 'alert',
                    ...$headers,
                ])
                ->withBody(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'application/json')
                ->post($host.'/3/device/'.$device->token);
        } catch (ConnectionException $e) {
            throw new ApnsException('Unable to reach APNs: '.$e->getMessage(), previous: $e);
        }

        if ($response->status() === 403 && in_array($response->json('reason'), ['ExpiredProviderToken', 'InvalidProviderToken'], true)) {
            $this->forgetToken();
        }

        return new ApnsResult($response->status(), $response->json('reason'));
    }

    /**
     * Provider authentication token (JWT signed with ES256). Apple accepts a
     * token for one hour and rejects refreshing it more than every 20 minutes.
     */
    public function token(): string
    {
        return Cache::remember($this->cacheKey(), now()->addMinutes(45), fn () => $this->makeToken());
    }

    public function forgetToken(): void
    {
        Cache::forget($this->cacheKey());
    }

    protected function makeToken(): string
    {
        $key = openssl_pkey_get_private((string) $this->settings->get('apns.private_key'));

        if ($key === false) {
            throw new ApnsException('The APNs private key is not a valid .p8 key.');
        }

        $segments = [
            $this->base64Url(json_encode(['alg' => 'ES256', 'kid' => $this->settings->string('apns.key_id')])),
            $this->base64Url(json_encode(['iss' => $this->settings->string('apns.team_id'), 'iat' => time()])),
        ];

        if (! openssl_sign(implode('.', $segments), $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new ApnsException('Unable to sign the APNs token.');
        }

        $segments[] = $this->base64Url(self::derToRaw($der));

        return implode('.', $segments);
    }

    /**
     * OpenSSL produces DER encoded ECDSA signatures; JWT (ES256) expects the
     * raw 64 bytes concatenation of R and S.
     */
    public static function derToRaw(string $der): string
    {
        $offset = 2;

        if (ord($der[1]) & 0x80) {
            $offset += ord($der[1]) & 0x7F;
        }

        $parts = [];

        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            $value = substr($der, $offset + 2, $length);
            $parts[] = str_pad(ltrim($value, "\x00"), 32, "\x00", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return implode('', $parts);
    }

    protected function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    protected function cacheKey(): string
    {
        return 'apns.jwt.'.sha1($this->settings->string('apns.key_id').'|'.$this->settings->string('apns.team_id').'|'.$this->settings->get('apns.private_key'));
    }
}
