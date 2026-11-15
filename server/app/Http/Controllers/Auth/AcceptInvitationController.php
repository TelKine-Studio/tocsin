<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AcceptInvitationController extends Controller
{
    public function show(string $token): View
    {
        $invitation = Invitation::findPendingByToken($token);

        if (! $invitation) {
            return view('auth.invitation-invalid');
        }

        return view('auth.accept-invitation', [
            'invitation' => $invitation,
            'token' => $token,
            'existingAccount' => User::query()->where('email', $invitation->email)->exists(),
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $invitation = Invitation::findPendingByToken($token);

        abort_unless($invitation, 404);
        abort_if(User::query()->where('email', $invitation->email)->exists(), 409, __('An account already exists for this address.'));

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = DB::transaction(function () use ($invitation, $data) {
            $invitation->forceFill(['accepted_at' => now()])->save();

            return User::create([
                'name' => $data['name'],
                'email' => $invitation->email,
                'password' => $data['password'],
                'role' => $invitation->role,
            ]);
        });

        Auth::login($user);
        Audit::log('user.activated', $user, user: $user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('status', __('Your account has been created. Welcome!'));
    }
}
