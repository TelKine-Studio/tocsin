<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));

        $users = User::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->withCount(['ownedTopics', 'devices'])
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $invitations = Invitation::query()
            ->pending()
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->with('inviter')
            ->latest()
            ->get();

        return view('admin.users.index', [
            'users' => $users,
            'search' => $search,
            'invitations' => $invitations,
            'roles' => Role::cases(),
            'mailConfigured' => config('mail.default') !== 'log',
        ]);
    }

    public function edit(User $user): View
    {
        return view('admin.users.edit', ['user' => $user, 'roles' => Role::cases()]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'role' => ['required', Rule::enum(Role::class)],
        ]);

        $data['is_active'] = $request->boolean('is_active');

        if ($user->is($request->user()) && ($data['role'] !== Role::Admin->value || ! $data['is_active'])) {
            throw ValidationException::withMessages(['role' => __('You cannot remove your own administrator access.')]);
        }

        $changes = array_keys(array_diff_assoc(array_map('strval', $data), array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : (string) $v, $user->only(array_keys($data)))));
        $user->update($data);
        Audit::log('user.updated', $user, ['changed' => $changes, 'role' => $data['role'], 'active' => $data['is_active']]);

        if (! $user->is_active) {
            $user->tokens()->delete();
        }

        return redirect()->route('admin.users.index')->with('status', __('User updated.'));
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_if($user->is($request->user()), 422, __('You cannot delete your own account.'));

        $user->tokens()->delete();
        Audit::log('user.deleted', $user);
        $user->delete();

        return redirect()->route('admin.users.index')->with('status', __('User deleted.'));
    }
}
