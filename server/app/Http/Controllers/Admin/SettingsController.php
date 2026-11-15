<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Models\AccountUsage;
use App\Models\Device;
use App\Models\RelayClient;
use App\Models\RelayUsage;
use App\Models\User;
use App\Push\ApnsClient;
use App\Push\ApnsException;
use App\Push\Gateways\RelayGateway;
use App\Push\PushManager;
use App\Support\AccountQuota;
use App\Support\Audit;
use App\Support\RelayPlans;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class SettingsController extends Controller
{
    public const SECTIONS = ['general', 'security', 'push', 'mail', 'realtime', 'relay'];

    public function edit(Request $request, Settings $settings, string $section = 'general'): View
    {
        abort_unless(in_array($section, self::SECTIONS, true), 404);
        abort_if($section === 'relay' && ! config('tocsin.relay_operator') && ! config('tocsin.hosted'), 404);

        return view('admin.settings.'.$section, [
            'settings' => $settings,
            'section' => $section,
            'locales' => SetLocale::AVAILABLE,
            'realtime' => $settings->realtimeClientConfig(),
            'devices' => Device::query()->selectRaw('environment, count(*) as total')->groupBy('environment')->pluck('total', 'environment'),
            'myDevices' => $request->user()->devices()->count(),
            'relayUsage' => $section === 'push' && $settings->pushMode() === 'relay' ? app(RelayGateway::class)->usage() : null,
            'relayStats' => $section === 'relay' ? [
                'clients' => RelayClient::query()->count(),
                'active_today' => RelayUsage::query()->where('date', now()->toDateString())->count(),
                'sent_today' => (int) RelayUsage::query()->where('date', now()->toDateString())->sum('sent'),
            ] : null,
            'hostedStats' => $section === 'relay' && AccountQuota::enabled() ? [
                'accounts' => User::query()->where('role', Role::User)->count(),
                'paid' => User::query()->where('role', Role::User)->whereIn('plan', RelayPlans::PAID)->count(),
                'messages_today' => (int) AccountUsage::query()->where('date', now()->toDateString())->sum('messages'),
            ] : null,
        ]);
    }

    public function updateGeneral(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'app_name' => ['required', 'string', 'max:60'],
            'app_url' => ['required', 'url:http,https', 'max:255'],
            'app_locale' => ['required', Rule::in(array_keys(SetLocale::AVAILABLE))],
            'retention_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'max_length' => ['required', 'integer', 'min:64', 'max:65535'],
        ]);

        $settings->set([
            'app.name' => $data['app_name'],
            'app.url' => rtrim($data['app_url'], '/'),
            'app.locale' => $data['app_locale'],
            'registration.enabled' => $request->boolean('registration_enabled'),
            'topics.users_can_create' => $request->boolean('users_can_create_topics'),
            'messages.retention_days' => (int) $data['retention_days'],
            'messages.max_length' => (int) $data['max_length'],
        ]);

        Audit::log('settings.updated', null, ['section' => 'general']);

        return back()->with('status', __('Settings saved.'));
    }

    public function updateMail(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'mailer' => ['required', Rule::in(['log', 'smtp', 'resend'])],
            'host' => ['nullable', 'required_if:mailer,smtp', 'string', 'max:255'],
            'port' => ['nullable', 'required_if:mailer,smtp', 'integer', 'between:1,65535'],
            'scheme' => ['nullable', Rule::in(['smtp', 'smtps'])],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'resend_key' => [
                'nullable', 'string', 'max:255', 'starts_with:re_',
                Rule::requiredIf(fn () => $request->input('mailer') === 'resend' && blank($settings->get('mail.resend_key'))),
            ],
            'from_address' => ['nullable', 'required_if:mailer,smtp,resend', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
        ]);

        $values = [
            'mail.mailer' => $data['mailer'],
            'mail.host' => $data['host'] ?? null,
            'mail.port' => isset($data['port']) ? (int) $data['port'] : null,
            'mail.scheme' => $data['scheme'] ?? null,
            'mail.username' => $data['username'] ?? null,
            'mail.from_address' => $data['from_address'] ?? null,
            'mail.from_name' => $data['from_name'] ?? null,
        ];

        // An empty password field keeps the stored password.
        if (filled($data['password'] ?? null)) {
            $values['mail.password'] = $data['password'];
        } elseif ($request->boolean('clear_password')) {
            $values['mail.password'] = null;
        }

        // Same for the Resend API key.
        if (filled($data['resend_key'] ?? null)) {
            $values['mail.resend_key'] = $data['resend_key'];
        }

        $settings->set($values);

        Audit::log('settings.updated', null, ['section' => 'mail']);

        return back()->with('status', __('Mail settings saved.'));
    }

    public function updatePush(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'team_id' => ['required', 'string', 'alpha_num', 'size:10'],
            'key_id' => ['required', 'string', 'alpha_num', 'size:10'],
            'bundle_id' => ['required', 'string', 'max:155', 'regex:/^[A-Za-z0-9.-]+$/'],
            'private_key' => ['nullable', 'string', 'max:4000'],
            'private_key_file' => ['nullable', 'file', 'max:8'],
        ], [
            'team_id.size' => __('The Team ID has 10 characters (Apple Developer → Membership).'),
            'key_id.size' => __('The Key ID has 10 characters (shown next to your key).'),
        ]);

        $key = $request->file('private_key_file')?->get() ?? ($data['private_key'] ?? null);

        $values = [
            'apns.team_id' => strtoupper($data['team_id']),
            'apns.key_id' => strtoupper($data['key_id']),
            'apns.bundle_id' => $data['bundle_id'],
        ];

        if (filled($key)) {
            $key = trim($key);

            if (! str_contains($key, 'PRIVATE KEY') || openssl_pkey_get_private($key) === false) {
                return back()->withInput()->withErrors(['private_key' => __('This is not a valid .p8 key. Paste the whole file, including the BEGIN and END lines.')]);
            }

            $values['apns.private_key'] = $key;
        } elseif (! $settings->get('apns.private_key')) {
            return back()->withInput()->withErrors(['private_key' => __('Upload or paste your .p8 key.')]);
        }

        $settings->set([...$values, 'push.mode' => 'direct']);
        app(ApnsClient::class)->forgetToken();

        Audit::log('settings.updated', null, ['section' => 'push', 'mode' => 'direct']);

        return back()->with('status', __('Push settings saved.'));
    }

    /** Firebase service account used to reach Android devices in direct mode. */
    public function updateFcm(Request $request, Settings $settings): RedirectResponse
    {
        $request->validate(['service_account' => ['required', 'file', 'max:16']]);

        $json = json_decode((string) $request->file('service_account')->get(), true);

        if (! isset($json['client_email'], $json['private_key'], $json['project_id']) || openssl_pkey_get_private($json['private_key']) === false) {
            return back()->withErrors(['service_account' => __('This is not a Firebase service account key (JSON).')]);
        }

        $settings->set(['fcm.service_account' => json_encode($json)]);
        Audit::log('settings.updated', null, ['section' => 'push', 'fcm_project' => $json['project_id']]);

        return back()->with('status', __('Firebase settings saved.'));
    }

    /** Registers this instance on a push relay (self-service, free plan). */
    public function connectRelay(Request $request, Settings $settings, RelayGateway $relay): RedirectResponse
    {
        $data = $request->validate(['relay_url' => ['required', 'url:http,https', 'max:255']]);
        $url = rtrim($data['relay_url'], '/');

        try {
            $credentials = $relay->register(
                $url,
                $settings->string('app.name'),
                $settings->get('app.url') ?: url('/'),
                $request->user()->email,
            );
        } catch (ApnsException $e) {
            return back()->withInput()->withErrors(['relay_url' => __('Connection to the relay failed: :error', ['error' => $e->getMessage()])]);
        }

        $settings->set([
            'push.mode' => 'relay',
            'relay.url' => $url,
            'relay.key' => $credentials['key'],
            'relay.bundle_id' => $credentials['bundle_id'] ?? null,
            'relay.plan' => $credentials['plan'] ?? 'free',
            'relay.daily_limit' => $credentials['daily_limit'] ?? null,
        ]);

        Audit::log('relay.connected', null, ['relay' => $url]);

        return back()->with('status', __('Connected to the relay. Push notifications are enabled.'));
    }

    /** Uses an existing relay key (e.g. one provided with a paid plan). */
    public function saveRelayKey(Request $request, Settings $settings, RelayGateway $relay): RedirectResponse
    {
        $data = $request->validate([
            'relay_url' => ['required', 'url:http,https', 'max:255'],
            'relay_key' => ['required', 'string', 'max:100'],
        ]);

        $settings->set(['push.mode' => 'relay', 'relay.url' => rtrim($data['relay_url'], '/'), 'relay.key' => trim($data['relay_key'])]);

        if ($relay->usage() === null) {
            $settings->set(['relay.key' => null]);

            return back()->withInput()->withErrors(['relay_key' => __('The relay did not accept this key.')]);
        }

        return back()->with('status', __('Relay key saved.'));
    }

    /** Starts the payment of a paid relay plan (redirects to the payment page). */
    public function relayCheckout(Request $request, RelayGateway $relay): RedirectResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'string', 'max:32'],
            'provider' => ['required', Rule::in(['cinetpay', 'stripe'])],
        ]);

        try {
            $url = $relay->checkout($data['plan'], $data['provider'], route('admin.settings.edit', ['section' => 'push', 'payment' => 'done']));
        } catch (ApnsException $e) {
            return back()->withErrors(['alert' => $e->getMessage()]);
        }

        return redirect()->away($url);
    }

    public function disconnectRelay(Settings $settings): RedirectResponse
    {
        $settings->set(['relay.key' => null, 'relay.plan' => null, 'relay.daily_limit' => null, 'relay.bundle_id' => null]);

        Audit::log('relay.disconnected');

        return back()->with('status', __('Disconnected from the relay.'));
    }

    public function updateConfidential(Request $request, Settings $settings): RedirectResponse
    {
        $settings->set(['push.confidential' => $request->boolean('confidential')]);
        Audit::log('settings.updated', null, ['section' => 'push', 'confidential' => $request->boolean('confidential')]);

        return back()->with('status', __('Settings saved.'));
    }

    public function switchPushMode(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate(['mode' => ['required', Rule::in(['relay', 'direct'])]]);
        $settings->set(['push.mode' => $data['mode']]);

        return back();
    }

    public function updateSecurity(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'sso_label' => ['nullable', 'string', 'max:60'],
            'sso_issuer' => ['nullable', 'required_if:sso_enabled,1', 'url:https,http', 'max:255'],
            'sso_client_id' => ['nullable', 'required_if:sso_enabled,1', 'string', 'max:255'],
            'sso_client_secret' => ['nullable', 'string', 'max:500'],
            'sso_allowed_domains' => ['nullable', 'string', 'max:500'],
        ]);

        $values = [
            'security.require_2fa_admins' => $request->boolean('require_2fa_admins'),
            'sso.enabled' => $request->boolean('sso_enabled'),
            'sso.label' => $data['sso_label'] ?? null,
            'sso.issuer' => isset($data['sso_issuer']) ? rtrim($data['sso_issuer'], '/') : null,
            'sso.client_id' => $data['sso_client_id'] ?? null,
            'sso.auto_create' => $request->boolean('sso_auto_create'),
            'sso.allowed_domains' => $data['sso_allowed_domains'] ?? null,
        ];

        if (filled($data['sso_client_secret'] ?? null)) {
            $values['sso.client_secret'] = $data['sso_client_secret'];
        }

        if ($values['security.require_2fa_admins'] && ! $request->user()->hasTwoFactor()) {
            return back()->withInput()->withErrors(['require_2fa_admins' => __('Enable two-factor authentication on your own account first.')]);
        }

        $settings->set($values);
        Audit::log('settings.updated', null, ['section' => 'security', 'sso' => $values['sso.enabled'], 'require_2fa_admins' => $values['security.require_2fa_admins']]);

        return back()->with('status', __('Security settings saved.'));
    }

    public function updateRelayServer(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'free_daily_limit' => ['required', 'integer', 'min:0', 'max:10000000'],
            'burst_per_minute' => ['required', 'integer', 'min:1', 'max:100000'],
            'currency' => ['required', 'string', 'size:3'],
            'pro_price' => ['nullable', 'integer', 'min:0'],
            'pro_daily_limit' => ['required', 'integer', 'min:1'],
            'business_price' => ['nullable', 'integer', 'min:0'],
            'business_daily_limit' => ['required', 'integer', 'min:1'],
            'hosted_free_max_topics' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'cinetpay_site_id' => ['nullable', 'string', 'max:50'],
            'cinetpay_api_key' => ['nullable', 'string', 'max:200'],
            'stripe_secret' => ['nullable', 'string', 'max:200'],
            'stripe_webhook_secret' => ['nullable', 'string', 'max:200'],
        ]);

        $settings->set([
            // The relay switches only exist in relay operator mode.
            ...(config('tocsin.relay_operator') ? [
                'relay_server.enabled' => $request->boolean('enabled'),
                'relay_server.registration' => $request->boolean('registration'),
            ] : []),
            ...(AccountQuota::enabled() ? ['hosted.free_max_topics' => (int) ($data['hosted_free_max_topics'] ?? 0)] : []),
            'relay_server.free_daily_limit' => (int) $data['free_daily_limit'],
            'relay_server.burst_per_minute' => (int) $data['burst_per_minute'],
            'relay_server.currency' => strtoupper($data['currency']),
            'relay_server.pro_price' => (int) ($data['pro_price'] ?? 0),
            'relay_server.pro_daily_limit' => (int) $data['pro_daily_limit'],
            'relay_server.business_price' => (int) ($data['business_price'] ?? 0),
            'relay_server.business_daily_limit' => (int) $data['business_daily_limit'],
            'payments.cinetpay_site_id' => $data['cinetpay_site_id'] ?? null,
            ...collect(['cinetpay_api_key', 'stripe_secret', 'stripe_webhook_secret'])
                ->filter(fn ($key) => filled($data[$key] ?? null))
                ->mapWithKeys(fn ($key) => ['payments.'.$key => $data[$key]])
                ->all(),
        ]);

        Audit::log('settings.updated', null, ['section' => 'relay']);

        return back()->with('status', __('Relay settings saved.'));
    }

    public function testPush(Request $request, PushManager $push, Settings $settings): RedirectResponse
    {
        $devices = $request->user()->devices()->get();

        if ($devices->isEmpty()) {
            return back()->withErrors(['alert' => __('No device is registered with your account. Sign in to the iPhone app first.')]);
        }

        $payload = [
            'aps' => [
                'alert' => ['title' => $settings->string('app.name'), 'body' => __('Push notifications work! 🎉')],
                'sound' => 'default',
            ],
        ];

        try {
            $results = $push->gateway()->send($devices, $payload);
        } catch (ApnsException $e) {
            return back()->withErrors(['alert' => $e->getMessage()]);
        }

        $sent = collect($results)->filter->successful()->count();
        $errors = collect($results)->reject->successful()->map(fn ($r) => $r->reason ?? 'HTTP '.$r->status)->unique();

        if ($sent === 0) {
            return back()->withErrors(['alert' => __('Apple refused the notification: :error', ['error' => $errors->implode(', ')])]);
        }

        return back()->with('status', trans_choice('Test notification sent to :count device.|Test notification sent to :count devices.', $sent));
    }

    public function testMail(Request $request): RedirectResponse
    {
        try {
            Mail::raw(__('This is a test email sent by :app. Your mail settings work!', ['app' => config('app.name')]), function ($message) use ($request) {
                $message->to($request->user()->email)->subject(__('Test email from :app', ['app' => config('app.name')]));
            });
        } catch (Throwable $e) {
            return back()->withErrors(['alert' => __('The test email could not be sent: :error', ['error' => $e->getMessage()])]);
        }

        return back()->with('status', __('A test email has been sent to :email.', ['email' => $request->user()->email]));
    }
}
