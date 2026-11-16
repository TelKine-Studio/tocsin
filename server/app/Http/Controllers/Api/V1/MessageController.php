<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\PublishMessage;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\Topic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MessageController extends Controller
{
    /**
     * List the messages of a topic, newest first.
     *
     * Query parameters: "since" (only messages with a greater id) and
     * "limit" (1-200, default 50).
     */
    public function index(Request $request, Topic $topic): AnonymousResourceCollection
    {
        abort_unless($topic->canRead($request->user()), $request->user() ? 403 : 401, __('You are not allowed to read this topic.'));

        $validated = $request->validate([
            'since' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $messages = $topic->messages()
            ->delivered()
            ->when($validated['since'] ?? null, fn ($query, $since) => $query->where('id', '>', $since))
            ->orderByDesc('id')
            ->limit($validated['limit'] ?? 50)
            ->get()
            ->each->setRelation('topic', $topic);

        return MessageResource::collection($messages);
    }

    /**
     * Publish a message.
     *
     * Accepts a JSON or form body ({"message": "...", "title": "...", ...})
     * or, ntfy style, a plain text body with optional X-Title, X-Priority,
     * X-Tags and X-Click headers.
     */
    public function store(Request $request, Topic $topic, PublishMessage $publish): JsonResponse
    {
        abort_unless($topic->canWrite($request->user()), $request->user() ? 403 : 401, __('You are not allowed to publish to this topic.'));

        $message = $publish->handle($topic, $this->input($request), $request->user());

        return (new MessageResource($message))->response()->setStatusCode(201);
    }

    /** @return array<string, mixed> */
    protected function input(Request $request): array
    {
        $structured = $request->isJson() || in_array($request->getContentTypeFormat(), ['form'], true);

        $field = fn (string $name, array $headers) => $request->input($name)
            ?? collect($headers)->map(fn (string $header) => $request->header($header))->first(fn ($value) => filled($value))
            ?? $request->query($name);

        $body = $structured ? ($request->input('message') ?? $request->input('body')) : null;

        // "curl -d 'Backup done' ..." sends a form content type with a raw body.
        if ($body === null && ! $request->isJson()) {
            $body = $request->getContent();
        }

        return [
            'body' => $body,
            'title' => $field('title', ['X-Title', 'Title']),
            'priority' => $field('priority', ['X-Priority', 'Priority']),
            'tags' => $field('tags', ['X-Tags', 'Tags']),
            'click' => $field('click', ['X-Click', 'Click']),
            'collapse' => $field('collapse', ['X-Collapse', 'Collapse']),
            'ack' => $field('ack', ['X-Ack', 'Ack']),
            'ack_group' => $field('ack_group', ['X-Ack-Group', 'Ack-Group']),
        ];
    }
}
