<?php

namespace App\Policies;

use App\Models\Topic;
use App\Models\User;
use App\Support\Settings;

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

    public function create(User $user): bool
    {
        return $user->isAdmin() || $this->settings->boolean('topics.users_can_create');
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
