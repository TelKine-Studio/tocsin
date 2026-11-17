<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TopicResource;
use App\Models\Topic;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TopicController extends Controller
{
    /**
     * Topics the caller is allowed to subscribe to.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $topics = Topic::query()
            ->readableBy($request->user())
            ->with('members')
            ->orderBy('name')
            ->get()
            // A token limited to some topics only lists those.
            ->filter(fn (Topic $topic) => $topic->canRead($request->user()))
            ->values();

        return TopicResource::collection($topics);
    }

    public function show(Request $request, Topic $topic): TopicResource
    {
        $this->ensureCanRead($request, $topic);

        return new TopicResource($topic);
    }

    protected function ensureCanRead(Request $request, Topic $topic): void
    {
        abort_unless($topic->canRead($request->user()), $request->user() ? 403 : 401, __('You are not allowed to read this topic.'));
    }
}
