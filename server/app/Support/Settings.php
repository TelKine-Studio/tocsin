<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use PDOException;

/**
 * Application settings stored in the database and editable from the
 * administration UI. Values that would traditionally live in a .env file
 * (site name, mail server, realtime credentials, ...) are managed here so
 * that an instance can be fully configured from the browser.
 */
class Settings
{
    /** @var array<string, mixed> */
    public const DEFAULTS = [
        'app.name' => 'Tocsin',
        'app.url' => null,
        'app.locale' => 'en',

        'registration.enabled' => false,
        'topics.users_can_create' => true,
        'topics.auto_create' => true,
        'attachments.max_size_mb' => 10,

        'messages.retention_days' => 30,
        'messages.max_length' => 4096,

        'security.require_2fa_admins' => false,
        'sso.enabled' => false,
        'sso.label' => null,
        'sso.issuer' => null,
        'sso.client_id' => null,
        'sso.client_secret' => null,
        'sso.auto_create' => false,
        'sso.allowed_domains' => null,

        'mail.mailer' => 'log',
        'mail.host' => null,
        'mail.port' => 587,
        'mail.scheme' => null,
        'mail.username' => null,
        'mail.password' => null,
        'mail.resend_key' => null,
        'mail.from_address' => null,
        'mail.from_name' => null,

        // Push delivery: "relay" (through a Tocsin relay, e.g. Telkine's) or
        // "direct" (with this instance's own APNs key).
        'push.mode' => 'relay',
        'push.confidential' => false,
        'relay.url' => null,
        'relay.key' => null,
        'relay.bundle_id' => null,
        'relay.plan' => null,
        'relay.daily_limit' => null,

        // This instance acting as a relay for other instances.
        'relay_server.enabled' => false,
        // Hosted mode: topics a free account can own (0 = unlimited).
        'hosted.free_max_topics' => 10,
        'relay_server.registration' => true,
        'relay_server.free_daily_limit' => 1000,
        'relay_server.burst_per_minute' => 600,
        'relay_server.currency' => 'XOF',
        'relay_server.pro_price' => 0,
        'relay_server.pro_daily_limit' => 50000,
        'relay_server.business_price' => 0,
        'relay_server.business_daily_limit' => 500000,
        'payments.cinetpay_site_id' => null,
        'payments.cinetpay_api_key' => null,
        'payments.stripe_secret' => null,
        'payments.stripe_webhook_secret' => null,

        'fcm.service_account' => null,
        'webpush.public_key' => null,
        'webpush.private_key' => null,

        'apns.key_id' => null,
        'apns.team_id' => null,
        'apns.bundle_id' => 'ci.telkine.tocsin',
        'apns.private_key' => null,

        'realtime.app_id' => null,
        'realtime.key' => null,
        'realtime.secret' => null,
    ];

    /** Settings encrypted at rest with the application key. */
    public const ENCRYPTED = ['mail.password', 'mail.resend_key', 'apns.private_key', 'relay.key', 'sso.client_secret', 'payments.cinetpay_api_key', 'payments.stripe_secret', 'payments.stripe_webhook_secret', 'fcm.service_account', 'webpush.private_key'];

    /** @var array<string, mixed>|null */
    protected ?array $values = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $values = $this->load();

        if (array_key_exists($key, $values) && $values[$key] !== null) {
            return $values[$key];
        }

        return $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public function string(string $key, string $default = ''): string
    {
        return (string) ($this->get($key) ?? $default);
    }

    public function integer(string $key, int $default = 0): int
    {
        return (int) ($this->get($key) ?? $default);
    }

    public function boolean(string $key, bool $default = false): bool
    {
        return filter_var($this->get($key) ?? $default, FILTER_VALIDATE_BOOLEAN);
    }

    /** @param array<string, mixed> $values */
    public function set(array $values): void
    {
        foreach ($values as $key => $value) {
            $stored = $value === null ? null : json_encode($value);

            if ($stored !== null && in_array($key, self::ENCRYPTED, true)) {
                $stored = Crypt::encryptString($stored);
            }

            Setting::query()->updateOrCreate(['key' => $key], ['value' => $stored]);
        }

        $this->values = null;
        $this->applyToConfig();
    }

    public function ssoEnabled(): bool
    {
        return $this->boolean('sso.enabled') && filled($this->get('sso.issuer')) && filled($this->get('sso.client_id'));
    }

    /** Whether this instance can deliver push notifications (relay or direct). */
    /** This instance sells the push relay (operator mode + relay enabled). */
    public function relayServerActive(): bool
    {
        return (bool) config('tocsin.relay_operator') && $this->boolean('relay_server.enabled');
    }

    public function pushConfigured(): bool
    {
        return $this->pushMode() === 'relay'
            ? filled($this->get('relay.key'))
            : ($this->apnsConfigured() || $this->fcmConfigured());
    }

    public function pushMode(): string
    {
        return $this->string('push.mode') === 'direct' ? 'direct' : 'relay';
    }

    /** Base URL of the relay this instance sends its notifications through. */
    public function relayUrl(): string
    {
        return rtrim($this->get('relay.url') ?: (string) config('tocsin.relay.url'), '/');
    }

    /** Bundle identifier of the iOS app the notifications are delivered to. */
    public function pushBundleId(): ?string
    {
        if (! $this->pushConfigured()) {
            return null;
        }

        return $this->pushMode() === 'relay' ? $this->get('relay.bundle_id') : $this->string('apns.bundle_id');
    }

    public function fcmConfigured(): bool
    {
        $account = json_decode((string) $this->get('fcm.service_account'), true);

        return isset($account['client_email'], $account['private_key'], $account['project_id']);
    }

    /** Whether Apple Push Notification service credentials are configured. */
    public function apnsConfigured(): bool
    {
        return filled($this->get('apns.key_id'))
            && filled($this->get('apns.team_id'))
            && filled($this->get('apns.bundle_id'))
            && filled($this->get('apns.private_key'));
    }

    /**
     * Generate the realtime (Reverb / Pusher protocol) credentials once.
     */
    public function ensureRealtimeCredentials(): void
    {
        if ($this->get('realtime.key') && $this->get('realtime.secret') && $this->get('realtime.app_id')) {
            return;
        }

        $this->set([
            'realtime.app_id' => (string) random_int(100000, 999999),
            'realtime.key' => Str::lower(Str::random(20)),
            'realtime.secret' => Str::lower(Str::random(32)),
        ]);
    }

    /**
     * Push the stored settings into Laravel's runtime configuration.
     */
    public function applyToConfig(): void
    {
        $values = $this->load();

        if ($values === []) {
            return;
        }

        config([
            'app.name' => $this->string('app.name'),
            'app.locale' => $this->string('app.locale'),
        ]);

        if ($url = $this->get('app.url')) {
            config(['app.url' => rtrim($url, '/')]);

            // HTTP requests keep using the host they were made on, so that a
            // misconfigured URL can never lock administrators out. Console
            // processes (queue, scheduler) have no request to rely on.
            if (app()->runningInConsole()) {
                app('url')->forceRootUrl(rtrim($url, '/'));
            }
        }

        $this->applyMailConfig();
        $this->applyRealtimeConfig();
    }

    protected function applyMailConfig(): void
    {
        $mailer = $this->string('mail.mailer');

        config([
            'mail.default' => in_array($mailer, ['smtp', 'resend', 'log'], true) ? $mailer : 'log',
            'mail.mailers.smtp.host' => $this->get('mail.host'),
            'mail.mailers.smtp.port' => $this->integer('mail.port'),
            'mail.mailers.smtp.scheme' => $this->get('mail.scheme'),
            'mail.mailers.smtp.username' => $this->get('mail.username'),
            'mail.mailers.smtp.password' => $this->get('mail.password'),
            'services.resend.key' => $this->get('mail.resend_key'),
            'mail.from.address' => $this->get('mail.from_address') ?: 'tocsin@'.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost'),
            'mail.from.name' => $this->get('mail.from_name') ?: $this->string('app.name'),
        ]);
    }

    protected function applyRealtimeConfig(): void
    {
        if (! $this->get('realtime.key')) {
            return;
        }

        $app = [
            'key' => $this->get('realtime.key'),
            'secret' => $this->get('realtime.secret'),
            'app_id' => $this->get('realtime.app_id'),
        ];

        config([
            'broadcasting.connections.reverb.key' => $app['key'],
            'broadcasting.connections.reverb.secret' => $app['secret'],
            'broadcasting.connections.reverb.app_id' => $app['app_id'],
            'reverb.apps.apps.0.key' => $app['key'],
            'reverb.apps.apps.0.secret' => $app['secret'],
            'reverb.apps.apps.0.app_id' => $app['app_id'],
        ]);
    }

    /**
     * Connection details handed to clients (web UI, mobile app) so that they
     * can open a WebSocket to the realtime server.
     *
     * @return array{key: string|null, host: string, port: int, scheme: string, path: string}
     */
    public function realtimeClientConfig(): array
    {
        $base = $this->get('app.url') ?: (app()->runningInConsole() ? config('app.url') : request()->getSchemeAndHttpHost());
        $url = parse_url((string) $base) ?: [];

        $scheme = config('tocsin.realtime.scheme') ?: ($url['scheme'] ?? 'https');
        $host = config('tocsin.realtime.host') ?: ($url['host'] ?? 'localhost');
        $port = config('tocsin.realtime.port') ?: ($url['port'] ?? ($scheme === 'https' ? 443 : 80));

        return [
            'key' => $this->get('realtime.key'),
            'host' => $host,
            'port' => (int) $port,
            'scheme' => $scheme,
            'path' => (string) config('tocsin.realtime.path', ''),
        ];
    }

    /** Forget the in-memory cache (useful in long running processes). */
    public function refresh(): void
    {
        $this->values = null;
    }

    /** @return array<string, mixed> */
    protected function load(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        try {
            $rows = Setting::query()->pluck('value', 'key')->all();
        } catch (QueryException|PDOException) {
            // The database is not migrated yet: fall back to defaults.
            return [];
        }

        return $this->values = Arr::map($rows, fn (?string $value, string $key) => $this->decode($key, $value));
    }

    protected function decode(string $key, ?string $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (in_array($key, self::ENCRYPTED, true)) {
            try {
                $value = Crypt::decryptString($value);
            } catch (DecryptException|MissingAppKeyException) {
                // Wrong or missing APP_KEY: the secret is unreadable, but the
                // rest of the application must keep working.
                return null;
            }
        }

        return json_decode($value, true);
    }
}
