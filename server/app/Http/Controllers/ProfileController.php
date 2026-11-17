<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Models\Topic;
use App\Support\Audit;
use App\Support\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
            'tokens' => $request->user()->tokens()->latest()->get(),
            'writableTopics' => Topic::query()->readableBy($request->user())->orderBy('name')->get()->filter(fn ($t) => $t->canWrite($request->user()))->values(),
            'devices' => $request->user()->devices()->withCount('topics')->latest('last_seen_at')->get(),
            'locales' => SetLocale::AVAILABLE,
            'twoFactorQr' => $request->user()->two_factor_secret && ! $request->user()->hasTwoFactor()
                ? app(TwoFactor::class)->qrCodeSvg(app(TwoFactor::class)->otpauthUrl($request->user(), $request->user()->two_factor_secret))
                : null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'locale' => ['nullable', Rule::in(array_keys(SetLocale::AVAILABLE))],
        ]);

        // The email receives password resets: changing it needs the password,
        // so that an open session alone cannot take the account over.
        if ($data['email'] !== $user->email) {
            $request->validate(['email_password' => ['required', 'current_password']], [
                'email_password.required' => __('Enter your current password to change your email address.'),
            ]);
            Audit::log('auth.email_changed', $user, ['from' => $user->email, 'to' => $data['email']]);
        }

        $user->update($data);

        if ($user->locale && array_key_exists($user->locale, SetLocale::AVAILABLE)) {
            app()->setLocale($user->locale);
        }

        return back()->with('status', __('Profile updated.'));
    }

    public function destroyDevice(Request $request, int $device): RedirectResponse
    {
        $request->user()->devices()->whereKey($device)->delete();

        return back()->with('status', __('Device removed. It will no longer receive notifications.'));
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $request->user()->update(['password' => $data['password']]);

        // Every other browser session of the account is closed.
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $request->user()->id)
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }

        return back()->with('status', __('Password updated.'));
    }
}
