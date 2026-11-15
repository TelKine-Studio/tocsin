@php
    /** @var \App\Models\Message|null $message */
    $priority = $message?->priority?->value ?? 3;
@endphp
<article data-message-id="{{ $message?->id }}" data-priority="{{ $priority }}" @if ($message) id="message-{{ $message->id }}" @endif
    class="scroll-mt-24 group relative flex gap-4 border-t border-zinc-100 px-5 py-4 first:border-0 sm:px-6 dark:border-zinc-800">
    <span class="absolute inset-y-3 left-0 w-1 rounded-r-full bg-transparent group-data-[priority=4]:bg-amber-400 group-data-[priority=5]:bg-red-500"></span>
    <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-center gap-2">
            <h3 data-field="title" class="font-semibold text-zinc-900 dark:text-white" @if (! $message?->title) hidden @endif>{{ $message?->title }}</h3>
            <span data-field="priority" @if ($priority === 3) hidden @endif
                class="inline-flex items-center gap-1.5 rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs font-medium text-zinc-700 group-data-[priority=4]:bg-amber-50 group-data-[priority=4]:text-amber-800 group-data-[priority=5]:bg-red-50 group-data-[priority=5]:text-red-700 dark:bg-white/5 dark:text-zinc-300 dark:group-data-[priority=4]:bg-amber-500/10 dark:group-data-[priority=4]:text-amber-300 dark:group-data-[priority=5]:bg-red-500/10 dark:group-data-[priority=5]:text-red-300">{{ $message?->priority?->label() }}</span>
            @if (($message?->occurrences ?? 1) > 1)
                <span title="{{ __('Received :count times, last :time', ['count' => $message->occurrences, 'time' => $message->last_seen_at?->diffForHumans()]) }}"
                    class="inline-flex items-center gap-1 rounded-full bg-zinc-900 px-2 py-0.5 text-xs font-semibold text-white tabular-nums dark:bg-white dark:text-zinc-900"><x-ui.icon name="layers" class="size-3" />×{{ $message->occurrences }}</span>
            @endif
        </div>
        <p data-field="message" class="prose-message mt-1">{{ $message?->body }}</p>
        @if ($message && ($attachment = $message->attachmentUrl()))
            @if (str_starts_with((string) $message->attachment_type, 'image/') || preg_match('/\.(png|jpe?g|gif|webp)$/i', (string) $message->attachment_name))
                <a href="{{ $attachment }}" target="_blank" rel="noopener" class="mt-3 block max-w-sm overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-800">
                    <img src="{{ $attachment }}" alt="{{ $message->attachment_name }}" loading="lazy" class="max-h-64 w-full object-cover">
                </a>
            @else
                <a href="{{ $attachment }}" target="_blank" rel="noopener" class="mt-3 inline-flex max-w-sm items-center gap-3 rounded-xl border border-zinc-200 px-3 py-2 text-sm transition hover:bg-zinc-50 dark:border-zinc-800 dark:hover:bg-white/5">
                    <x-ui.icon name="upload" class="size-4 rotate-180 text-zinc-400" />
                    <span class="min-w-0 flex-1 truncate font-medium text-zinc-700 dark:text-zinc-200">{{ $message->attachment_name }}</span>
                    @if ($message->attachment_size)<span class="text-xs text-zinc-400">{{ \Illuminate\Support\Number::fileSize($message->attachment_size) }}</span>@endif
                </a>
            @endif
        @endif
        @if (! empty($message?->actions))
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($message->actions as $action)
                    <a href="{{ $action['url'] }}" target="_blank" rel="noopener noreferrer" title="{{ $action['action'] === 'http' ? $action['method'].' '.$action['url'] : $action['url'] }}"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-2.5 py-1 text-xs font-medium text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-white/5">
                        <x-ui.icon :name="$action['action'] === 'http' ? 'zap' : 'external'" class="size-3" />{{ $action['label'] }}
                    </a>
                @endforeach
            </div>
        @endif
        @if ($message?->requires_ack)
            @if ($message->isAcknowledged())
                <p class="mt-3 inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
                    <x-ui.icon name="check-circle" class="size-3.5" />{{ $message->acknowledgementLabel() }}
                    <span class="font-normal text-emerald-600/80 dark:text-emerald-400/70">· <time datetime="{{ $message->acknowledged_at->toIso8601String() }}" title="{{ $message->acknowledged_at->isoFormat('LLLL') }}">{{ $message->acknowledged_at->diffForHumans() }}</time></span>
                </p>
            @else
                <div class="mt-3 flex flex-wrap items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 dark:border-amber-500/20 dark:bg-amber-500/10">
                    <span class="inline-flex min-w-0 flex-1 items-center gap-1.5 text-xs font-medium text-amber-800 dark:text-amber-200">
                        <x-ui.icon name="bell" class="size-3.5 shrink-0" />
                        <span>{{ __('Waiting for acknowledgement') }}@if ($message->escalation_level > 0) · {{ trans_choice(':count reminder sent|:count reminders sent', $message->escalation_level) }}@endif
                            @if ($message->next_escalation_at) · {{ __('next reminder :time', ['time' => $message->next_escalation_at->diffForHumans()]) }}@endif</span>
                    </span>
                    <form method="POST" action="{{ route('topics.messages.acknowledge', [$topic, $message]) }}">
                        @csrf
                        <x-ui.button size="sm" icon="check">{{ __('Acknowledge') }}</x-ui.button>
                    </form>
                </div>
            @endif
        @endif
        <div class="mt-2.5 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-zinc-500">
            <time data-field="time" datetime="{{ $message?->created_at?->toIso8601String() }}" title="{{ $message?->created_at?->isoFormat('LLLL') }}">{{ $message?->created_at?->diffForHumans() }}</time>
            <span data-field="tags" class="font-medium text-brand-600 dark:text-brand-400" @if (empty($message?->tags)) hidden @endif>{{ collect($message?->tags ?? [])->map(fn ($t) => '#'.$t)->implode('  ') }}</span>
            @if ($message?->click_url)
                <a href="{{ $message->click_url }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 hover:text-zinc-800 dark:hover:text-zinc-200"><x-ui.icon name="external" class="size-3" />{{ parse_url($message->click_url, PHP_URL_HOST) }}</a>
            @endif
            @if ($message?->user)
                <span class="inline-flex items-center gap-1.5"><x-ui.avatar :user="$message->user" size="xs" class="ring-0" />{{ $message->user->name }}</span>
            @endif
            @if ($message && $message->push_status)
                @php
                    [$color, $label] = match ($message->push_status) {
                        'sent' => ['text-emerald-600 dark:text-emerald-400', trans_choice('Delivered to :count device|Delivered to :count devices', $message->push_count)],
                        'partial' => ['text-amber-600 dark:text-amber-400', __(':sent delivered, :failed failed', ['sent' => $message->push_count, 'failed' => $message->push_failed])],
                        'failed' => ['text-red-600 dark:text-red-400', __('Push failed')],
                        'quota' => ['text-red-600 dark:text-red-400', __('Relay quota reached')],
                        'no_devices' => ['text-zinc-400', __('No subscribed device')],
                        'disabled' => ['text-zinc-400', __('Push not configured')],
                        default => ['text-zinc-400', __('Sending…')],
                    };
                @endphp
                <span class="inline-flex items-center gap-1 {{ $color }}" @if ($message->push_error) title="{{ $message->push_error }}" @endif><x-ui.icon name="smartphone" class="size-3" />{{ $label }}</span>
            @endif
        </div>
    </div>
    @if ($message && ($canManage ?? false))
        <form method="POST" action="{{ route('topics.messages.destroy', [$topic, $message]) }}" class="opacity-100 transition sm:opacity-0 sm:group-hover:opacity-100 sm:focus-within:opacity-100"
            data-confirm-title="{{ __('Delete this message?') }}" data-confirm="{{ __('It will no longer appear in the history.') }}" data-confirm-action="{{ __('Delete') }}">
            @csrf
            @method('DELETE')
            <x-ui.button variant="ghost-danger" size="icon" icon="trash" class="size-8"><span class="sr-only">{{ __('Delete') }}</span></x-ui.button>
        </form>
    @endif
</article>
