<?php

namespace App\Policies;

use App\Models\Topic;
use App\Models\User;
use App\Support\AccountQuota;
use App\Support\Settings;
use Illuminate\Auth\Access\Response;

class TopicPolicy
{
    public function __construct(protected Settings $settings) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(?User $user, Topic $topic): bool
    {
        return $topic->canRead($user);
    }

    public function create(User $user): Response|bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        // Hosted mode: every account creates its own topics, within its plan.
        if (AccountQuota::enabled()) {
            $max = $user->maxTopics();

            return $max === null || $user->ownedTopics()->count() < $max
                ? Response::allow()
                : Response::deny(__('Your plan allows :count topics. Delete one or upgrade to create more.', ['count' => $max]));
        }

        return $this->settings->boolean('topics.users_can_create');
    }

    public function publish(?User $user, Topic $topic): bool
    {
        return $topic->canWrite($user);
    }

    public function update(User $user, Topic $topic): bool
    {
        return $topic->isManagedBy($user);
    }

    public function delete(User $user, Topic $topic): bool
    {
        return $topic->isManagedBy($user);
    }
}
