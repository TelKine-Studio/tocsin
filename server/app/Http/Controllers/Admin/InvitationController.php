<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Notifications\InvitationNotification;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Adding a user = inviting them: the person receives a link and chooses
 * their own password. Invitations are managed from the users page.
 */
class InvitationController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::enum(Role::class)],
        ], [
            'email.unique' => __('A user with this email address already exists.'),
        ]);

        // Replace any previous pending invitation for the same address.
        Invitation::query()->pending()->where('email', $data['email'])->delete();

        [$invitation, $token] = Invitation::issue($data['email'], Role::from($data['role']), $request->user(), $data['name']);
        Audit::log('user.invited', $invitation, ['role' => $data['role']]);

        return $this->deliver($request, $invitation, $token);
    }

    /** Sends a fresh link (the previous one stops working). */
    public function resend(Request $request, Invitation $invitation): RedirectResponse
    {
        abort_if($invitation->accepted_at !== null, 404);

        $invitation->delete();
        [$fresh, $token] = Invitation::issue($invitation->email, $invitation->role, $request->user(), $invitation->name);

        return $this->deliver($request, $fresh, $token);
    }

    public function destroy(Invitation $invitation): RedirectResponse
    {
        $invitation->delete();

        return back()->with('status', __('Invitation revoked.'));
    }

    protected function deliver(Request $request, Invitation $invitation, string $token): RedirectResponse
    {
        $url = route('invitations.show', $token);
        $mailed = false;

        if ($request->boolean('send_email') && config('mail.default') !== 'log') {
            try {
                Notification::route('mail', $invitation->email)->notify(new InvitationNotification($invitation, $url));
                $mailed = true;
            } catch (Throwable $e) {
                Log::error('Unable to send invitation', ['error' => $e->getMessage()]);
            }
        }

        return redirect()->route('admin.users.index')
            ->with('status', $mailed
                ? __(':name has been invited: an email with a link to choose a password was sent to :email.', ['name' => $invitation->name ?? $invitation->email, 'email' => $invitation->email])
                : __(':name has been added. Share the link below so they can choose a password.', ['name' => $invitation->name ?? $invitation->email]))
            ->with('invitation_url', $url)
            ->with('invitation_email', $invitation->email);
    }
}
