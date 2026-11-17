<x-layouts.app :title="__('Audit log')">
    <x-ui.page-header :title="__('Audit log')" :description="__('Who did what on this instance: sign-ins, members, keys and settings. Kept :days days.', ['days' => App\Models\AuditLog::RETENTION_DAYS])" />

    <div class="card" x-data="liveList('audit-results')" data-live-also="audit-clear">
        <form method="GET" data-live class="flex flex-col gap-3 border-b border-zinc-100 p-4 sm:flex-row sm:items-center sm:px-6 dark:border-zinc-800">
            <div class="relative w-full sm:max-w-sm">
                <x-ui.icon name="search" class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-zinc-400" />
                <x-ui.icon name="refresh" class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 animate-spin text-zinc-400" x-show="loading" x-cloak />
                <input type="search" name="q" autocomplete="off" value="{{ $search }}" placeholder="{{ __('Search by person, name or IP…') }}" class="form-control pl-10">
            </div>
            <x-ui.select name="action" :options="$groups" :value="$action" class="sm:w-60" />
            <span id="audit-clear" class="sm:ml-auto">
            @if ($search !== '' || $action !== '')
                <a data-live-link href="{{ route('admin.audit') }}" class="text-sm font-medium text-zinc-500 hover:text-zinc-900 dark:hover:text-white">{{ __('Clear filters') }}</a>
            @endif
            </span>
        </form>

        <div id="audit-results" class="transition-opacity" :class="loading && 'opacity-60'">

        @if ($days->isNotEmpty())
            <div class="hidden grid-cols-[56px_32px_minmax(0,1fr)_170px_120px_16px] gap-x-3.5 border-b border-zinc-200 bg-zinc-50 px-6 py-2.5 text-xs font-medium text-zinc-500 lg:grid dark:border-zinc-800 dark:bg-white/[.03]">
                <span>{{ __('Time') }}</span><span></span><span>{{ __('Event') }}</span><span>{{ __('Device') }}</span><span>{{ __('IP address') }}</span><span></span>
            </div>
        @endif

        @forelse ($days as $day => $entries)
            @php($date = \Illuminate\Support\Carbon::parse($day))
            <section class="last:[&>ul]:border-b-0 last:[&_li:last-child>button]:rounded-b-2xl">
                <h2 class="flex items-baseline justify-between gap-3 border-b border-zinc-100 bg-zinc-50/70 px-4 py-2 text-xs font-medium text-zinc-500 sm:px-6 dark:border-zinc-800 dark:bg-white/[0.02]">
                    <span class="first-letter:uppercase">{{ $date->isToday() ? __('Today') : ($date->isYesterday() ? __('Yesterday') : $date->isoFormat($date->isCurrentYear() ? 'dddd D MMMM' : 'dddd D MMMM YYYY')) }}</span>
                    <span class="text-zinc-400">{{ trans_choice(':count event|:count events', $entries->count()) }}</span>
                </h2>
                <ul class="divide-y divide-zinc-100 border-b border-zinc-100 dark:divide-zinc-800 dark:border-zinc-800">
                    @foreach ($entries as $entry)
                        @php($details = $entry->details())
                        <li x-data="{ open: false }">
                            <button type="button" @click="open = ! open" :aria-expanded="open.toString()"
                                class="grid w-full grid-cols-[32px_minmax(0,1fr)_16px] items-start gap-x-3.5 px-4 py-3 text-left transition hover:bg-zinc-50 sm:px-6 lg:grid-cols-[56px_32px_minmax(0,1fr)_170px_120px_16px] lg:items-center dark:hover:bg-white/[0.03]">
                                <time class="hidden text-xs text-zinc-500 tabular-nums lg:block" datetime="{{ $entry->log->created_at->toIso8601String() }}" title="{{ $entry->log->created_at->isoFormat('LLLL') }}">{{ $entry->log->created_at->isoFormat('LT') }}</time>
                                <span @class([
                                    'inline-flex size-8 shrink-0 items-center justify-center rounded-lg',
                                    'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400' => $entry->danger(),
                                    'bg-zinc-100 text-zinc-500 dark:bg-white/5 dark:text-zinc-400' => ! $entry->danger(),
                                ])><x-ui.icon :name="$entry->icon()" class="size-4" /></span>
                                <span class="min-w-0">
                                    <span class="block text-sm text-zinc-700 dark:text-zinc-200">{{ $entry->sentence() }}</span>
                                    <span class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-zinc-500 lg:hidden">
                                        <time datetime="{{ $entry->log->created_at->toIso8601String() }}">{{ $entry->log->created_at->isoFormat('LT') }}</time>
                                        @if ($device = $entry->device())<span aria-hidden="true" class="text-zinc-300 dark:text-zinc-700">·</span><span>{{ $device }}</span>@endif
                                        @if ($entry->log->ip_address)<span aria-hidden="true" class="text-zinc-300 dark:text-zinc-700">·</span><span class="font-mono text-[11px]">{{ $entry->log->ip_address }}</span>@endif
                                    </span>
                                    @if ($details)
                                        <span class="mt-1.5 flex flex-wrap gap-1.5">
                                            @foreach (array_slice($details, 0, 3) as $detail)
                                                <span class="inline-flex max-w-full items-center gap-1 rounded bg-zinc-100 px-1.5 py-0.5 text-[11px] text-zinc-600 dark:bg-white/5 dark:text-zinc-300">
                                                    <span class="shrink-0 text-zinc-400">{{ $detail['label'] }}</span>
                                                    <span class="truncate font-medium">{{ $detail['value'] }}</span>
                                                </span>
                                            @endforeach
                                        </span>
                                    @endif
                                </span>
                                <span class="hidden truncate text-xs text-zinc-600 lg:block dark:text-zinc-400">{{ $entry->device() ?? '—' }}</span>
                                <span class="hidden truncate font-mono text-[11px] text-zinc-500 lg:block">{{ $entry->log->ip_address ?? '—' }}</span>
                                <x-ui.icon name="chevron-down" class="mt-2 size-4 shrink-0 text-zinc-400 transition lg:mt-0" ::class="open && 'rotate-180'" />
                            </button>
                            <div x-show="open" x-collapse x-cloak>
                                <dl class="mx-4 mb-4 grid gap-x-6 gap-y-3 rounded-lg border border-zinc-100 bg-zinc-50/70 p-4 text-sm sm:mr-6 sm:ml-[4.25rem] sm:grid-cols-2 lg:ml-[7.6rem] dark:border-zinc-800 dark:bg-white/[0.02]">
                                    @foreach ([...$details, ...$entry->context()] as $row)
                                        <div @class(['min-w-0', 'sm:col-span-2' => $row['small'] ?? false])>
                                            <dt class="text-xs text-zinc-500">{{ $row['label'] }}</dt>
                                            <dd @class(['mt-0.5 break-words text-zinc-900 dark:text-zinc-100', 'font-mono text-xs' => $row['mono'] ?? false, '!text-zinc-500' => $row['small'] ?? false])>{{ $row['value'] }}</dd>
                                        </div>
                                    @endforeach
                                </dl>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            <x-ui.empty icon="shield" :title="$search !== '' || $action !== '' ? __('No event matches these filters') : __('No event yet')" />
        @endforelse

        @if ($logs->hasPages())
            <div data-live-pages class="px-6 py-4">{{ $logs->links() }}</div>
        @endif
        </div>
    </div>
</x-layouts.app>
