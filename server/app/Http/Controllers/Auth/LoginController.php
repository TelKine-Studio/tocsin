<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(Settings $settings): View
    {
        return view('auth.login', [
            'registrationEnabled' => $settings->boolean('registration.enabled'),
            'sso' => $settings->ssoEnabled() ? ($settings->get('sso.label') ?: __('single sign-on')) : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $credentials['email'] = mb_strtolower($credentials['email']);

        if (! Auth::validate([...$credentials, 'is_active' => true])) {
            Audit::log('auth.login_failed', null, ['email' => $credentials['email']]);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $user = User::query()->where('email', $credentials['email'])->firstOrFail();

        // Second step: one-time code from the authenticator app.
        if ($user->hasTwoFactor()) {
            $request->session()->put(['login.id' => $user->id, 'login.remember' => $request->boolean('remember')]);

            return redirect()->route('two-factor.challenge');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        Audit::log('auth.login', $user);

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Audit::log('auth.logout');
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
