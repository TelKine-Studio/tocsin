<?php

namespace App\Http\Controllers;

use App\Actions\AcknowledgeMessage;
use App\Actions\PublishMessage;
use App\Models\Message;
use App\Models\Topic;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class TopicMessageController extends Controller
{
    public function store(Request $request, Topic $topic, PublishMessage $publish): RedirectResponse
    {
        $this->authorize('publish', $topic);

        $request->validate([
            'attachment' => ['nullable', 'file', 'max:'.(app(Settings::class)->integer('attachments.max_size_mb', 10) * 1024)],
            'send_at' => ['nullable', 'date', 'after:now'],
        ]);

        $input = $request->only(['body', 'title', 'priority', 'tags', 'click']);

        if ($file = $request->file('attachment')) {
            $input['file'] = ['contents' => $file->get(), 'name' => $file->getClientOriginalName(), 'type' => $file->getMimeType()];
        }

        if ($request->filled('send_at')) {
            $input['delay'] = Carbon::parse($request->input('send_at'), $request->input('timezone') ?: config('app.timezone'))->getTimestamp();
        }

        $message = $publish->handle($topic, $input, $request->user());

        return redirect()->route('topics.show', $topic)->with('status', $message->isScheduled()
            ? __('Message scheduled for :date.', ['date' => $message->scheduled_at->isoFormat('LLL')])
            : __('Message published.'));
    }

    /** Rendered message card, used by the topic page to refresh it live. */
    public function show(Request $request, Topic $topic, Message $message): Response
    {
        $this->authorize('view', $topic);

        abort_if($message->isScheduled(), 404);

        return response()->view('topics.partials.message', [
            'topic' => $topic,
            'message' => $message->load(['user', 'acknowledger']),
            'canManage' => $request->user()->can('update', $topic),
        ]);
    }

    public function acknowledge(Request $request, Topic $topic, Message $message, AcknowledgeMessage $acknowledge): RedirectResponse
    {
        $this->authorize('view', $topic);

        if (! $message->requires_ack) {
            return back();
        }

        return back()->with('status', $acknowledge->handle($message->setRelation('topic', $topic), $request->user())
            ? __('Message acknowledged. Reminders are stopped.')
            : __('This message was already acknowledged by :name.', ['name' => $message->acknowledgedByName()]));
    }

    public function destroy(Topic $topic, Message $message): RedirectResponse
    {
        $this->authorize('update', $topic);

        $message->delete();

        return back()->with('status', __('Message deleted.'));
    }

    public function clear(Topic $topic): RedirectResponse
    {
        $this->authorize('update', $topic);

        $topic->messages()->delete();

        return back()->with('status', __('All messages of this topic have been deleted.'));
    }
}
