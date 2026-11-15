<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TwoFactorController extends Controller
{
    public function __construct(protected TwoFactor $twoFactor) {}

    /** Step 1: generate a secret and show the QR code (not active yet). */
    public function enable(Request $request): RedirectResponse
    {
        $request->user()->forceFill([
            'two_factor_secret' => $this->twoFactor->generateSecret(),
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        return redirect()->to(route('profile.edit').'#security');
    }

    /** Step 2: confirm with a code from the app, then show recovery codes. */
    public function confirm(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();

        if (! $user->two_factor_secret || ! $this->twoFactor->verify($user->two_factor_secret, $request->string('code'))) {
            throw ValidationException::withMessages(['code' => __('This code is invalid. Check the time of your phone and try again.')]);
        }

        $codes = $this->twoFactor->recoveryCodes();
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => $codes->all()])->save();
        Audit::log('auth.2fa_enabled', $user);

        return redirect()->to(route('profile.edit').'#security')
            ->with('status', __('Two-factor authentication is enabled.'))
            ->with('recovery_codes', $codes->all());
    }

    public function regenerateCodes(Request $request): RedirectResponse
    {
        $codes = $this->twoFactor->recoveryCodes();
        $request->user()->forceFill(['two_factor_recovery_codes' => $codes->all()])->save();

        return redirect()->to(route('profile.edit').'#security')->with('recovery_codes', $codes->all());
    }

    public function disable(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);

        $request->user()->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        Audit::log('auth.2fa_disabled', $request->user());

        return redirect()->to(route('profile.edit').'#security')->with('status', __('Two-factor authentication is disabled.'));
    }

    /** Login challenge after a correct password. */
    public function challenge(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('login.id')) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        $user = User::query()->find($request->session()->get('login.id'));

        if (! $user) {
            return redirect()->route('login');
        }

        $key = '2fa:'.$user->id.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => __('Too many attempts. Try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)])]);
        }

        $valid = $request->filled('recovery_code')
            ? $this->twoFactor->useRecoveryCode($user, (string) $request->input('recovery_code'))
            : $this->twoFactor->verify((string) $user->two_factor_secret, (string) $request->input('code'));

        if (! $valid) {
            RateLimiter::hit($key, 300);
            Audit::log('auth.2fa_failed', $user, user: $user);

            throw ValidationException::withMessages([$request->filled('recovery_code') ? 'recovery_code' : 'code' => __('This code is invalid.')]);
        }

        RateLimiter::clear($key);
        Auth::login($user, (bool) $request->session()->pull('login.remember'));
        $request->session()->forget('login.id');
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        Audit::log('auth.login', $user, ['two_factor' => true], $user);

        return redirect()->intended(route('dashboard'));
    }
}
