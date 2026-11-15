<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Models\Topic;
use App\Support\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $user->update($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'locale' => ['nullable', Rule::in(array_keys(SetLocale::AVAILABLE))],
        ]));

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

        return back()->with('status', __('Password updated.'));
    }
}
