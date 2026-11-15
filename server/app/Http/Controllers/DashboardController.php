<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Message;
use App\Models\Topic;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, Settings $settings): View
    {
        $user = $request->user();
        $readable = Topic::query()->readableBy($user)->select('id');

        $topics = Topic::query()
            ->readableBy($user)
            ->withCount(['messages', 'devices'])
            ->orderByDesc('last_message_at')
            ->orderBy('name')
            ->limit(5)
            ->get();

        $recentMessages = Message::query()
            ->delivered()
            ->whereIn('topic_id', $readable)
            ->with(['topic', 'user'])
            ->latest('id')
            ->limit(7)
            ->get();

        $days = collect(range(13, 0))->map(fn (int $i) => now()->subDays($i)->startOfDay());
        $counts = Message::query()
            ->whereIn('topic_id', $readable)
            ->where('created_at', '>=', $days->first())
            ->get(['created_at'])
            ->countBy(fn (Message $m) => $m->created_at->toDateString());
        $activity = $days->map(fn (Carbon $day) => ['date' => $day, 'count' => $counts->get($day->toDateString(), 0)]);

        $weekly = $activity->slice(7)->sum('count');
        $previousWeek = $activity->take(7)->sum('count');

        $stats = [
            'topics' => Topic::query()->readableBy($user)->count(),
            'messages_week' => $weekly,
            'trend' => $previousWeek > 0 ? (int) round(($weekly - $previousWeek) / $previousWeek * 100) : null,
            'devices' => Device::query()->whereHas('topics', fn ($q) => $q->whereIn('topics.id', $readable))->count(),
            'pushes' => (int) Message::query()->whereIn('topic_id', $readable)->where('created_at', '>=', now()->subDays(7))->sum('push_count'),
        ];

        $onboarding = collect([
            ['done' => $settings->pushConfigured(), 'title' => __('Enable push notifications'), 'text' => __('Connect to the Telkine relay in one click, or add your own APNs key.'), 'href' => $user->isAdmin() ? route('admin.settings.edit', 'push') : null],
            ['done' => $stats['topics'] > 0, 'title' => __('Create a topic'), 'text' => __('A topic is a channel your scripts publish to.'), 'href' => route('topics.create')],
            ['done' => $stats['devices'] > 0, 'title' => __('Subscribe from the iPhone app'), 'text' => __('Open the Tocsin app, enter this server URL and the topic name.'), 'href' => null],
            ['done' => $activity->sum('count') > 0 || Message::query()->whereIn('topic_id', $readable)->exists(), 'title' => __('Publish your first message'), 'text' => __('From the topic page, the API or a simple curl command.'), 'href' => $topics->first() ? route('topics.show', $topics->first()) : null],
        ]);

        return view('dashboard', compact('topics', 'recentMessages', 'stats', 'activity', 'onboarding'));
    }
}
