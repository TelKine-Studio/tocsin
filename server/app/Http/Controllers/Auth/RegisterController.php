<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AccountQuota;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Self-service sign up, only available when enabled in the settings.
 * Otherwise users join through invitations.
 */
class RegisterController extends Controller
{
    public function __construct(protected Settings $settings) {}

    public function create(): View
    {
        abort_unless($this->settings->boolean('registration.enabled'), 404);

        return view('auth.register');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->settings->boolean('registration.enabled'), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = User::create([...$data, 'role' => Role::User]);

        // Hosted mode: the free plan is only usable with a real address.
        if (AccountQuota::enabled()) {
            $user->sendEmailVerificationNotification();
        } else {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route(AccountQuota::enabled() ? 'verification.notice' : 'dashboard');
    }
}
