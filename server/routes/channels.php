<?php

use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Private topics: "private-topic.{name}". Public topics use a public
// channel ("topic.{name}") that needs no authorization at all.
Broadcast::channel('topic.{topic}', function (User $user, Topic $topic) {
    return $user->is_active && $topic->canRead($user);
});
