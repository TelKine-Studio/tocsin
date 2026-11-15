<?php

namespace App\Http\Controllers;

use App\Actions\PublishMessage;
use App\Integrations\WebhookFormatter;
use App\Models\Topic;
use App\Models\TopicWebhook;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WebhookController extends Controller
{
    /** POST /hooks/{token} — public endpoint called by the other tool. */
    public function receive(Request $request, string $token, WebhookFormatter $formatter, PublishMessage $publisher): JsonResponse
    {
        $webhook = TopicWebhook::query()->where('token', $token)->with(['topic', 'user'])->first();

        if (! $webhook) {
            return response()->json(['error' => 'unknown_webhook'], 404);
        }

        try {
            $message = $publisher->handle($webhook->topic, $formatter->format($webhook->source, $request), $webhook->user);
        } catch (ValidationException $e) {
            return response()->json(['error' => 'invalid', 'message' => collect($e->errors())->flatten()->first()], 422);
        }

        $webhook->increment('calls', 1, ['last_used_at' => now()]);

        return response()->json(['ok' => true, 'id' => $message->id], 202);
    }

    public function store(Request $request, Topic $topic): RedirectResponse
    {
        $this->authorize('update', $topic);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'source' => ['required', Rule::in(TopicWebhook::SOURCES)],
        ]);

        $topic->webhooks()->create([...$data, 'user_id' => $request->user()->id]);
        Audit::log('webhook.created', $topic, ['name' => $data['name'], 'source' => $data['source']]);

        return redirect()->to(route('topics.show', $topic).'#integrate')->with('status', __('Webhook created. Paste its URL in the other tool.'));
    }

    public function destroy(Topic $topic, TopicWebhook $webhook): RedirectResponse
    {
        $this->authorize('update', $topic);
        abort_unless($webhook->topic_id === $topic->id, 404);

        $webhook->delete();
        Audit::log('webhook.deleted', $topic, ['name' => $webhook->name]);

        return redirect()->to(route('topics.show', $topic).'#integrate')->with('status', __('Webhook deleted.'));
    }
}
