<?php

use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Private topics: "private-topic.{name}". Public topics use a public
// channel ("topic.{name}") that needs no authorization at all.
Broadcast::channel('topic.{topic}', function (User $user, Topic $topic) {
    return $user->is_active && $topic->channel_key === null && $topic->canRead($user);
});

// After a member was removed, the channel name carries a new key.
Broadcast::channel('topic.{topic}.{key}', function (User $user, Topic $topic, string $key) {
    return $user->is_active && $topic->channel_key !== null && hash_equals($topic->channel_key, $key) && $topic->canRead($user);
});
