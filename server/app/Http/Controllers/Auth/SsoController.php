<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Single sign-on with any OpenID Connect provider (Google Workspace,
 * Microsoft Entra ID, Keycloak, Authentik, Okta...), configured from
 * Settings → Security. Authorization code flow with PKCE.
 */
class SsoController extends Controller
{
    public function __construct(protected Settings $settings) {}

    public function redirect(Request $request): RedirectResponse
    {
        abort_unless($this->settings->ssoEnabled(), 404);

        $config = $this->discovery();
        $verifier = Str::random(64);
        $state = Str::random(40);

        $request->session()->put('sso', ['state' => $state, 'verifier' => $verifier]);

        return redirect()->away($config['authorization_endpoint'].'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->settings->get('sso.client_id'),
            'redirect_uri' => route('sso.callback'),
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => Str::random(32),
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]));
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless($this->settings->ssoEnabled(), 404);

        $session = $request->session()->pull('sso');

        if (! $session || ! hash_equals($session['state'], (string) $request->query('state')) || ! $request->filled('code')) {
            return $this->fail(__('The sign-in was cancelled or has expired. Please try again.'));
        }

        $config = $this->discovery();

        try {
            $token = Http::asForm()->acceptJson()->timeout(15)->post($config['token_endpoint'], [
                'grant_type' => 'authorization_code',
                'code' => $request->query('code'),
                'redirect_uri' => route('sso.callback'),
                'client_id' => $this->settings->get('sso.client_id'),
                'client_secret' => $this->settings->get('sso.client_secret'),
                'code_verifier' => $session['verifier'],
            ]);

            $profile = $token->successful()
                ? Http::withToken((string) $token->json('access_token'))->acceptJson()->timeout(15)->get($config['userinfo_endpoint'])
                : null;
        } catch (ConnectionException) {
            return $this->fail(__('The identity provider cannot be reached.'));
        }

        if (! $profile?->successful() || ! filter_var($profile->json('email'), FILTER_VALIDATE_EMAIL)) {
            return $this->fail(__('The identity provider did not return a verified email address.'));
        }

        if ($profile->json('email_verified') === false) {
            return $this->fail(__('Your email address is not verified by the identity provider.'));
        }

        $email = Str::lower($profile->json('email'));
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $domains = collect(explode(',', (string) $this->settings->get('sso.allowed_domains')))->map(fn ($d) => Str::lower(trim($d)))->filter();

            if (! $this->settings->boolean('sso.auto_create') || ($domains->isNotEmpty() && ! $domains->contains(Str::after($email, '@')))) {
                return $this->fail(__('No account exists for :email. Ask an administrator to add you.', ['email' => $email]));
            }

            $user = User::create([
                'name' => $profile->json('name') ?: Str::before($email, '@'),
                'email' => $email,
                'password' => Str::random(64),
                'role' => Role::User,
                'is_active' => true,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
            Audit::log('user.activated', $user, ['sso' => true], $user);
        }

        if (! $user->is_active) {
            return $this->fail(__('This account has been disabled.'));
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        Audit::log('auth.sso_login', $user, user: $user);

        return redirect()->intended(route('dashboard'));
    }

    /** @return array{authorization_endpoint: string, token_endpoint: string, userinfo_endpoint: string} */
    protected function discovery(): array
    {
        $issuer = rtrim((string) $this->settings->get('sso.issuer'), '/');

        return Cache::remember('sso.discovery.'.sha1($issuer), now()->addHour(), function () use ($issuer) {
            $response = Http::acceptJson()->timeout(10)->get($issuer.'/.well-known/openid-configuration');

            abort_unless($response->successful(), 502, __('Unable to read the configuration of the identity provider.'));

            return $response->json();
        });
    }

    protected function fail(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
