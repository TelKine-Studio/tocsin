@php
    $firstName = \Illuminate\Support\Str::before(auth()->user()->name, ' ');
    $hour = now()->hour;
    $greeting = $hour < 12 ? __('Good morning') : ($hour < 18 ? __('Good afternoon') : __('Good evening'));
    $maxActivity = max($activity->max('count'), 1);
    $done = $onboarding->where('done', true)->count();
@endphp
<x-layouts.app :title="__('Dashboard')">
    <x-ui.page-header :title="$greeting.', '.$firstName" :description="ucfirst(now()->isoFormat('dddd LL'))">
        <x-ui.button variant="secondary" :href="route('topics.index')" icon="hash">{{ __('All topics') }}</x-ui.button>
    </x-ui.page-header>

    @if (\App\Support\AccountQuota::enabled() && ! auth()->user()->isAdmin() && ! auth()->user()->hasVerifiedEmail())
        <div class="mb-6 flex flex-wrap items-center gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200">
            <x-ui.icon name="mail" class="size-4 shrink-0" />
            <span class="flex-1">{{ __('Confirm your email address to start publishing: we sent you a link.') }}</span>
            <form method="POST" action="{{ route('verification.send') }}">@csrf<x-ui.button size="sm" variant="secondary">{{ __('Send the link again') }}</x-ui.button></form>
        </div>
    @endif

    @if ($done < $onboarding->count())
        <section class="card relative mb-8 overflow-hidden">
            <div class="pointer-events-none absolute -top-24 -right-24 size-72 rounded-full bg-brand-500/10 blur-3xl"></div>
            <div class="relative grid gap-8 p-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.6fr)] lg:p-8">
                <div>
                    <x-ui.badge color="brand" icon="sparkles">{{ __('Getting started') }}</x-ui.badge>
                    <h2 class="mt-4 text-xl font-semibold tracking-tight text-zinc-900 dark:text-white">{{ __('Set up your notification server') }}</h2>
                    <p class="mt-2 text-sm leading-relaxed text-zinc-500 dark:text-zinc-400">{{ __('Follow these steps to receive your first push notification on your iPhone.') }}</p>
                    <div class="mt-6">
                        <div class="flex items-center justify-between text-xs font-medium text-zinc-500">
                            <span>{{ __(':done of :total completed', ['done' => $done, 'total' => $onboarding->count()]) }}</span>
                            <span>{{ round($done / $onboarding->count() * 100) }}%</span>
                        </div>
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                            <div class="h-full rounded-full bg-linear-to-r from-brand-500 to-brand-600 transition-all" style="width: {{ $done / $onboarding->count() * 100 }}%"></div>
                        </div>
                    </div>
                </div>
                <ol class="space-y-1">
                    @foreach ($onboarding as $index => $step)
                        @php $tag = $step['href'] && ! $step['done'] ? 'a' : 'div'; @endphp
                        <li>
                            <{{ $tag }} @if ($tag === 'a') href="{{ $step['href'] }}" @endif @class(['group flex items-start gap-4 rounded-xl p-3 transition', 'hover:bg-zinc-50 dark:hover:bg-white/[.03]' => $tag === 'a'])>
                                @if ($step['done'])
                                    <span class="inline-flex size-7 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-white"><x-ui.icon name="check" class="size-4" /></span>
                                @else
                                    <span class="inline-flex size-7 shrink-0 items-center justify-center rounded-full border-2 border-zinc-200 text-xs font-semibold text-zinc-500 dark:border-zinc-700">{{ $index + 1 }}</span>
                                @endif
                                <span class="min-w-0 flex-1">
                                    <span @class(['block text-sm font-medium', 'text-zinc-400 line-through decoration-zinc-300 dark:decoration-zinc-600' => $step['done'], 'text-zinc-900 dark:text-white' => ! $step['done']])>{{ $step['title'] }}</span>
                                    @unless ($step['done'])<span class="block text-sm text-zinc-500 dark:text-zinc-400">{{ $step['text'] }}</span>@endunless
                                </span>
                                @if ($tag === 'a')<x-ui.icon name="arrow-right" class="mt-1 size-4 text-zinc-300 transition group-hover:translate-x-0.5 group-hover:text-zinc-600 dark:group-hover:text-zinc-300" />@endif
                            </{{ $tag }}>
                        </li>
                    @endforeach
                </ol>
            </div>
        </section>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat :label="__('Messages this week')" :value="number_format($stats['messages_week'])" icon="activity" :series="$activity->pluck('count')->all()"
            :hint="$stats['trend'] === null ? __('No activity the week before') : ($stats['trend'] >= 0 ? '↑ '.$stats['trend'].'% '.__('vs last week') : '↓ '.abs($stats['trend']).'% '.__('vs last week'))" />
        <x-ui.stat :label="__('Push notifications sent')" :value="number_format($stats['pushes'])" icon="bell" :hint="__('Last 7 days')" />
        <x-ui.stat :label="__('Subscribed devices')" :value="number_format($stats['devices'])" icon="smartphone" :hint="__('iPhones receiving your topics')" />
        <x-ui.stat :label="__('Topics')" :value="number_format($stats['topics'])" icon="hash" :href="route('topics.index')" :hint="__('Accessible to you')" />
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <x-ui.card :title="__('Activity')" :description="__('Messages published over the last 14 days')" icon="bar-chart">
                <div class="flex h-44 items-end gap-1.5 sm:gap-2.5">
                    @foreach ($activity as $day)
                        <div class="group relative flex h-full flex-1 flex-col justify-end">
                            <div class="pointer-events-none absolute -top-2 left-1/2 z-10 -translate-x-1/2 -translate-y-full rounded-lg bg-zinc-900 px-2 py-1 text-xs whitespace-nowrap text-white opacity-0 shadow-lg transition group-hover:opacity-100 dark:bg-white dark:text-zinc-900">
                                {{ trans_choice(':count message|:count messages', $day['count']) }} · {{ $day['date']->isoFormat('D MMM') }}
                            </div>
                            <div @class(['w-full rounded-md transition-all group-hover:opacity-80', 'bg-linear-to-t from-brand-600 to-brand-400' => $day['count'] > 0, 'bg-zinc-100 dark:bg-zinc-800' => $day['count'] === 0])
                                style="height: {{ $day['count'] > 0 ? max(8, $day['count'] / $maxActivity * 100) : 4 }}%"></div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-3 flex justify-between text-xs text-zinc-400">
                    <span>{{ $activity->first()['date']->isoFormat('D MMM') }}</span>
                    <span>{{ __('Today') }}</span>
                </div>
            </x-ui.card>

            <x-ui.card :title="__('Latest messages')" icon="inbox" :padding="false">
                @forelse ($recentMessages as $message)
                    <a href="{{ route('topics.show', $message->topic) }}" class="flex gap-4 border-t border-zinc-100 px-5 py-4 transition first:border-0 hover:bg-zinc-50/80 sm:px-6 dark:border-zinc-800 dark:hover:bg-white/[.02]">
                        <x-ui.topic-avatar :topic="$message->topic" size="sm" />
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $message->title ?: \Illuminate\Support\Str::limit($message->body, 70) }}</p>
                                @if ($message->priority->value !== 3)<x-ui.priority :priority="$message->priority" />@endif
                            </div>
                            <p class="mt-0.5 truncate text-sm text-zinc-500 dark:text-zinc-400">
                                <span class="font-medium text-zinc-600 dark:text-zinc-300">{{ $message->topic->label() }}</span>
                                @if ($message->title) · {{ \Illuminate\Support\Str::limit($message->body, 90) }}@endif
                            </p>
                        </div>
                        <time class="shrink-0 text-xs text-zinc-400" datetime="{{ $message->created_at->toIso8601String() }}">{{ $message->created_at->diffForHumans(short: true) }}</time>
                    </a>
                @empty
                    <x-ui.empty icon="bell" :title="__('No messages yet')" :description="__('Messages published to your topics will show up here.')" />
                @endforelse
            </x-ui.card>
        </div>

        <x-ui.card :title="__('Your topics')" icon="hash" :padding="false" class="self-start">
            <x-slot:actions>
                <a href="{{ route('topics.index') }}" class="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">{{ __('View all') }}</a>
            </x-slot:actions>
            @forelse ($topics as $topic)
                <a href="{{ route('topics.show', $topic) }}" class="flex items-center gap-3 border-t border-zinc-100 px-5 py-3.5 transition first:border-0 hover:bg-zinc-50/80 sm:px-6 dark:border-zinc-800 dark:hover:bg-white/[.02]">
                    <x-ui.topic-avatar :topic="$topic" size="sm" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $topic->label() }}</span>
                        <span class="block truncate text-xs text-zinc-500">{{ $topic->last_message_at ? __('Last message :date', ['date' => $topic->last_message_at->diffForHumans()]) : __('No messages yet') }}</span>
                    </span>
                    <span class="flex items-center gap-1 text-xs text-zinc-400 tabular-nums" title="{{ __('Subscribed devices') }}"><x-ui.icon name="smartphone" class="size-3.5" />{{ $topic->devices_count }}</span>
                </a>
            @empty
                <x-ui.empty icon="hash" :title="__('No topics yet')" :description="__('Create a topic, then subscribe to it from the iPhone app.')">
                    @can('create', App\Models\Topic::class)
                        <x-ui.button :href="route('topics.create')" icon="plus" size="sm">{{ __('New topic') }}</x-ui.button>
                    @endcan
                </x-ui.empty>
            @endforelse
        </x-ui.card>
    </div>
</x-layouts.app>
