<x-layouts.app :title="__('Topics')">
    <x-ui.page-header :title="__('Topics')" :description="__('A topic is a channel: publish messages to it and every subscribed iPhone receives a push notification.')">
        @can('create', App\Models\Topic::class)
            <x-ui.button :href="route('topics.create')" icon="plus">{{ __('New topic') }}</x-ui.button>
        @endcan
    </x-ui.page-header>

    <div x-data="liveList('topic-results')" data-live-also="topic-tabs topic-visibility">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div id="topic-tabs" class="inline-flex rounded-xl border border-zinc-200 bg-white p-1 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            @foreach (['' => __('All'), 'public' => __('Public'), 'private' => __('Private')] as $value => $label)
                <a data-live-link href="{{ route('topics.index', array_filter(['q' => $search, 'visibility' => $value])) }}"
                    @class(['rounded-lg px-3.5 py-1.5 text-sm font-medium transition', 'bg-zinc-900 text-white shadow-sm dark:bg-white dark:text-zinc-900' => ($visibility?->value ?? '') === $value, 'text-zinc-500 hover:text-zinc-900 dark:hover:text-white' => ($visibility?->value ?? '') !== $value])>{{ $label }}</a>
            @endforeach
        </div>
        <form method="GET" data-live class="relative w-full sm:max-w-xs">
            <span id="topic-visibility">@if ($visibility)<input type="hidden" name="visibility" value="{{ $visibility->value }}">@endif</span>
            <x-ui.icon name="refresh" class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 animate-spin text-zinc-400" x-show="loading" x-cloak />
            <x-ui.icon name="search" class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-zinc-400" />
            <input type="search" name="q" autocomplete="off" value="{{ $search }}" placeholder="{{ __('Search topics…') }}" class="form-control pl-10">
        </form>
    </div>

    <div id="topic-results" class="transition-opacity" :class="loading && 'opacity-60'">
    @if ($topics->isEmpty())
        <div class="card">
            @if ($search || $visibility)
                <x-ui.empty icon="search" :title="__('No topic matches your search')" :description="__('Try another name or clear the filters.')">
                    <x-ui.button variant="secondary" :href="route('topics.index')">{{ __('Clear filters') }}</x-ui.button>
                </x-ui.empty>
            @else
                <x-ui.empty icon="hash" :title="__('Create your first topic')" :description="__('Topics group your notifications: backups, monitoring, deployments… Each one can be public or private.')">
                    @can('create', App\Models\Topic::class)
                        <x-ui.button :href="route('topics.create')" icon="plus">{{ __('New topic') }}</x-ui.button>
                    @endcan
                </x-ui.empty>
            @endif
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($topics as $topic)
                <a href="{{ route('topics.show', $topic) }}" class="card card-hover group flex flex-col p-5">
                    <div class="flex items-start gap-3.5">
                        <x-ui.topic-avatar :topic="$topic" />
                        <div class="min-w-0 flex-1">
                            <h3 class="truncate font-semibold text-zinc-900 group-hover:text-brand-700 dark:text-white dark:group-hover:text-brand-300">{{ $topic->label() }}</h3>
                            <p class="truncate font-mono text-xs text-zinc-500">#{{ $topic->name }}</p>
                        </div>
                        <x-ui.badge :color="$topic->isPublic() ? 'blue' : 'zinc'" :icon="$topic->isPublic() ? 'globe' : 'lock'">{{ $topic->visibility->label() }}</x-ui.badge>
                    </div>
                    <p class="mt-4 line-clamp-2 min-h-10 text-sm text-zinc-500 dark:text-zinc-400">{{ $topic->description ?: __('No description.') }}</p>
                    <div class="mt-5 flex items-center gap-4 border-t border-zinc-100 pt-4 text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                        <span class="flex items-center gap-1.5" title="{{ __('Messages') }}"><x-ui.icon name="inbox" class="size-3.5" />{{ number_format($topic->messages_count) }}</span>
                        <span class="flex items-center gap-1.5" title="{{ __('Subscribed devices') }}"><x-ui.icon name="smartphone" class="size-3.5" />{{ number_format($topic->devices_count) }}</span>
                        @unless ($topic->isPublic())
                            <span class="flex items-center gap-1.5" title="{{ __('Members') }}"><x-ui.icon name="users" class="size-3.5" />{{ $topic->members_count + 1 }}</span>
                        @endunless
                        <span class="ml-auto flex items-center gap-1.5"><x-ui.icon name="clock" class="size-3.5" />{{ $topic->last_message_at?->diffForHumans(short: true) ?? '—' }}</span>
                    </div>
                </a>
            @endforeach
        </div>
        @if ($topics->hasPages())
            <div data-live-pages class="mt-8">{{ $topics->links() }}</div>
        @endif
    @endif
    </div>
    </div>
</x-layouts.app>
