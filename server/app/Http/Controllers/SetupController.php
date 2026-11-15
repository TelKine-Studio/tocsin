<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Middleware\SetLocale;
use App\Models\User;
use App\Support\Installation;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * First-run setup: creates the first administrator and the base settings.
 * Only reachable while no user exists (see EnsureApplicationIsInstalled).
 */
class SetupController extends Controller
{
    public function show(Request $request): View
    {
        return view('setup', [
            'suggestedUrl' => $request->getSchemeAndHttpHost(),
            'locales' => SetLocale::AVAILABLE,
        ]);
    }

    public function store(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'site_name' => ['required', 'string', 'max:60'],
            'app_url' => ['required', 'url:http,https', 'max:255'],
            'locale' => ['required', Rule::in(array_keys(SetLocale::AVAILABLE))],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = DB::transaction(function () use ($data, $settings) {
            abort_if(User::query()->lockForUpdate()->exists(), 403, __('This instance is already installed.'));

            $settings->set([
                'app.name' => $data['site_name'],
                'app.url' => rtrim($data['app_url'], '/'),
                'app.locale' => $data['locale'],
            ]);

            return User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => Role::Admin,
                'locale' => $data['locale'],
            ]);
        });

        $settings->ensureRealtimeCredentials();
        Installation::flush();

        Auth::login($user, remember: true);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->route('dashboard')
            ->with('status', __('Welcome to :name! Your instance is ready.', ['name' => $data['site_name']]));
    }
}
