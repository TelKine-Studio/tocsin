<?php

namespace App\Http\Controllers;

use App\Models\Topic;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Grants users read and/or write access to a topic.
 */
class TopicMemberController extends Controller
{
    public function store(Request $request, Topic $topic): RedirectResponse
    {
        $this->authorize('update', $topic);

        $data = $request->validate(['email' => ['required', 'email']]);

        $user = User::query()->where('email', mb_strtolower($data['email']))->first();

        if (! $user) {
            throw ValidationException::withMessages(['email' => __('No user found with this email address.')]);
        }

        if ($user->id === $topic->owner_id) {
            throw ValidationException::withMessages(['email' => __('The owner already has full access to this topic.')]);
        }

        $topic->members()->syncWithoutDetaching([$user->id => [
            'can_read' => true,
            'can_write' => $request->boolean('can_write'),
        ]]);

        Audit::log('topic.member_added', $topic, ['member' => $user->email, 'can_write' => $request->boolean('can_write')]);

        return back()->with('status', __(':name now has access to this topic.', ['name' => $user->name]));
    }

    public function update(Request $request, Topic $topic, User $user): RedirectResponse
    {
        $this->authorize('update', $topic);

        $topic->members()->updateExistingPivot($user->id, [
            'can_read' => true,
            'can_write' => $request->boolean('can_write'),
        ]);

        Audit::log('topic.member_updated', $topic, ['member' => $user->email, 'can_write' => $request->boolean('can_write')]);

        return back()->with('status', __('Permissions updated.'));
    }

    public function destroy(Topic $topic, User $user): RedirectResponse
    {
        $this->authorize('update', $topic);

        $topic->members()->detach($user->id);
        if (! $topic->isPublic()) {
            $topic->rotateChannel();
        }
        Audit::log('topic.member_removed', $topic, ['member' => $user->email]);

        return back()->with('status', __('Access removed.'));
    }
}
