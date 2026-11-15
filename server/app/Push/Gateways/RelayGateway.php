<?php

namespace App\Push\Gateways;

use App\Push\ApnsException;
use App\Push\ApnsResult;
use App\Push\PushGateway;
use App\Support\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Sends notifications through a Tocsin relay (e.g. the one operated by
 * Telkine), which owns the APNs key of the official app. The relay only
 * forwards the notifications: it does not store their content.
 */
class RelayGateway implements PushGateway
{
    /** Notifications per request to the relay. */
    public const BATCH_SIZE = 100;

    public function __construct(protected Settings $settings) {}

    public function isConfigured(): bool
    {
        return filled($this->settings->get('relay.key'));
    }

    public function send(iterable $devices, array $payload, array $headers = []): array
    {
        $results = [];

        foreach (collect($devices)->chunk(self::BATCH_SIZE) as $chunk) {
            $response = $this->request('post', '/api/relay/v1/push', [
                'notifications' => $chunk->map(fn ($device) => [
                    'ref' => (string) $device->id,
                    'token' => $device->token,
                    'platform' => $device->platform,
                    'environment' => $device->environment,
                    'payload' => $payload,
                    'headers' => $headers,
                ])->values()->all(),
            ]);

            if ($response->status() === 429 && $response->json('error') === 'quota_exceeded') {
                foreach ($chunk as $device) {
                    $results[$device->id] = new ApnsResult(429, 'RelayQuotaExceeded');
                }

                continue;
            }

            if (! $response->successful()) {
                throw new ApnsException('The push relay answered HTTP '.$response->status().': '.($response->json('message') ?? $response->body()));
            }

            foreach ($response->json('results', []) as $result) {
                $results[(int) $result['ref']] = new ApnsResult((int) $result['status'], $result['reason'] ?? null);
            }

            $this->rememberUsage($response->json('usage'));
        }

        return $results;
    }

    /**
     * Registers this instance on the relay and returns its credentials.
     *
     * @return array{key: string, bundle_id: string|null, plan: string, daily_limit: int}
     */
    public function register(string $relayUrl, string $name, string $instanceUrl, string $email): array
    {
        try {
            $response = Http::acceptJson()->timeout(15)->post(rtrim($relayUrl, '/').'/api/relay/v1/instances', [
                'name' => $name,
                'url' => $instanceUrl,
                'email' => $email,
            ]);
        } catch (ConnectionException $e) {
            throw new ApnsException('Unable to reach the relay: '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw new ApnsException($response->json('message') ?? 'The relay refused the registration (HTTP '.$response->status().').');
        }

        return $response->json('data');
    }

    /** Creates a payment session for a paid plan and returns its URL. */
    public function checkout(string $plan, string $provider, string $returnUrl): string
    {
        $response = $this->request('post', '/api/relay/v1/checkout', ['plan' => $plan, 'provider' => $provider, 'return_url' => $returnUrl]);

        if (! $response->successful() || ! $response->json('data.url')) {
            throw new ApnsException($response->json('message') ?? 'The relay could not start the payment (HTTP '.$response->status().').');
        }

        return $response->json('data.url');
    }

    /** @return array<string, mixed>|null Usage of the current day on the relay. */
    public function usage(): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = $this->request('get', '/api/relay/v1/usage');
        } catch (ApnsException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $this->rememberUsage($response->json('data'));

        return $response->json('data');
    }

    protected function request(string $method, string $path, array $data = []): Response
    {
        try {
            return Http::acceptJson()
                ->withToken((string) $this->settings->get('relay.key'))
                ->timeout(20)
                ->{$method}($this->settings->relayUrl().$path, $data);
        } catch (ConnectionException $e) {
            throw new ApnsException('Unable to reach the push relay: '.$e->getMessage(), previous: $e);
        }
    }

    /** @param  array<string, mixed>|null  $usage */
    protected function rememberUsage(?array $usage): void
    {
        if (! $usage) {
            return;
        }

        $changed = collect([
            'relay.plan' => $usage['plan'] ?? null,
            'relay.daily_limit' => $usage['daily_limit'] ?? null,
            'relay.bundle_id' => $usage['bundle_id'] ?? null,
        ])->filter(fn ($value, $key) => $value !== null && $value !== $this->settings->get($key));

        if ($changed->isNotEmpty()) {
            $this->settings->set($changed->all());
        }
    }
}
