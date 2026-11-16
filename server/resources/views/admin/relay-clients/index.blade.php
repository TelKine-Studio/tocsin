<x-layouts.app :title="__('Relay')">
    <x-ui.page-header :title="__('Relay instances')" :description="__('Tocsin instances that deliver their notifications through this relay. Free plan: :count notifications per day.', ['count' => number_format($freeLimit, 0, ',', ' ')])">
        <x-ui.button variant="secondary" :href="route('admin.settings.edit', 'relay')" icon="settings">{{ __('Relay settings') }}</x-ui.button>
    </x-ui.page-header>

    @unless ($enabled)
        <div class="mb-6 flex gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200">
            <x-ui.icon name="alert" class="mt-0.5 size-4 shrink-0" />{{ __('The relay is disabled: instances cannot send notifications.') }}
        </div>
    @endunless

    @if (session('relay_key'))
        <section class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-5 dark:border-emerald-500/20 dark:bg-emerald-500/10">
            <p class="mb-3 text-sm font-semibold text-emerald-900 dark:text-emerald-200">{{ __('Send this key to the instance administrator. It is shown only once.') }}</p>
            <x-ui.code :label="__('Relay key')">{{ session('relay_key') }}</x-ui.code>
        </section>
    @endif

    <div class="card overflow-hidden" x-data="liveList('client-results')">
        <form method="GET" data-live class="border-b border-zinc-100 p-4 sm:px-6 dark:border-zinc-800">
            <div class="relative max-w-sm">
                <x-ui.icon name="search" class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-zinc-400" />
                <x-ui.icon name="refresh" class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 animate-spin text-zinc-400" x-show="loading" x-cloak />
                <input type="search" name="q" autocomplete="off" value="{{ $search }}" placeholder="{{ __('Search by name, URL or email…') }}" class="form-control pl-10">
            </div>
        </form>

        <div id="client-results" class="transition-opacity" :class="loading && 'opacity-60'">
        @forelse ($clients as $client)
            @php
                $limit = $client->effectiveDailyLimit();
                $used = ($client->todayUsage?->sent ?? 0) + ($client->todayUsage?->failed ?? 0);
                $percent = min(100, $used / max(1, $limit) * 100);
            @endphp
            <div x-data="{ edit: false }" class="border-t border-zinc-100 first:border-0 dark:border-zinc-800">
                <div class="flex flex-wrap items-center gap-4 px-5 py-4 sm:px-6">
                    <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-zinc-100 text-zinc-600 dark:bg-white/5 dark:text-zinc-300"><x-ui.icon name="globe" class="size-[18px]" /></span>
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-zinc-900 dark:text-white">
                            {{ $client->name }}
                            <x-ui.badge :color="match ($client->plan) { 'pro' => 'brand', 'business' => 'violet', default => 'zinc' }">{{ ucfirst($client->plan) }}</x-ui.badge>
                            @unless ($client->is_active)<x-ui.badge color="red" dot>{{ __('Disabled') }}</x-ui.badge>@endunless
                        </p>
                        <p class="truncate text-xs text-zinc-500">{{ $client->url }} · {{ $client->email }} · <span class="font-mono">{{ $client->key_prefix }}…</span>@if ($client->plan_expires_at) · {{ __('paid until :date', ['date' => $client->plan_expires_at->isoFormat('LL')]) }}@endif</p>
                    </div>
                    <div class="w-full sm:w-56">
                        <div class="flex justify-between text-xs text-zinc-500">
                            <span>{{ __('Today') }}</span>
                            <span class="tabular-nums">{{ number_format($used, 0, ',', ' ') }} / {{ number_format($limit, 0, ',', ' ') }}</span>
                        </div>
                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                            <div @class(['h-full rounded-full', 'bg-emerald-500' => $percent < 75, 'bg-amber-500' => $percent >= 75 && $percent < 100, 'bg-red-500' => $percent >= 100]) style="width: {{ $percent }}%"></div>
                        </div>
                        <p class="mt-1 text-[11px] text-zinc-400">{{ __(':count in 30 days', ['count' => number_format((int) $client->sent_30_days, 0, ',', ' ')]) }} · {{ $client->last_used_at?->diffForHumans() ?? __('never used') }}</p>
                    </div>
                    <x-ui.button type="button" variant="ghost" size="sm" @click="edit = !edit">{{ __('Manage') }}</x-ui.button>
                </div>

                <div x-show="edit" x-collapse x-cloak class="border-t border-zinc-100 bg-zinc-50/70 px-5 py-5 sm:px-6 dark:border-zinc-800 dark:bg-white/[.02]">
                    <form method="POST" action="{{ route('admin.relay-clients.update', $client) }}" class="grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                        @csrf
                        @method('PUT')
                        <x-ui.select name="plan" :label="__('Plan')" :value="$client->plan" :options="collect($plans)->mapWithKeys(fn ($p) => [$p => ucfirst($p)])" />
                        <x-ui.input name="daily_limit" type="number" min="0" :label="__('Daily limit')" :value="$client->daily_limit" :placeholder="__('Free plan (:count)', ['count' => $freeLimit])" />
                        <label class="flex items-center gap-2 pb-2.5 text-sm text-zinc-700 dark:text-zinc-300">
                            <input type="checkbox" name="is_active" value="1" @checked($client->is_active) class="size-4 rounded border-zinc-300 text-brand-600">{{ __('Active') }}
                        </label>
                        <div class="flex flex-wrap gap-2 sm:col-span-3">
                            <x-ui.button size="sm">{{ __('Save') }}</x-ui.button>
                        </div>
                    </form>
                    <div class="mt-4 flex flex-wrap gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-800">
                        <form method="POST" action="{{ route('admin.relay-clients.rotate', $client) }}" data-confirm-title="{{ __('Generate a new key?') }}" data-confirm="{{ __('The instance will stop sending notifications until its administrator enters the new key.') }}" data-confirm-action="{{ __('Generate') }}">
                            @csrf
                            <x-ui.button variant="secondary" size="sm" icon="refresh">{{ __('New key') }}</x-ui.button>
                        </form>
                        <form method="POST" action="{{ route('admin.relay-clients.destroy', $client) }}" data-confirm-title="{{ __('Remove :name from the relay?', ['name' => $client->name]) }}" data-confirm="{{ __('Its key stops working immediately.') }}" data-confirm-action="{{ __('Remove') }}">
                            @csrf
                            @method('DELETE')
                            <x-ui.button variant="ghost-danger" size="sm" icon="trash">{{ __('Remove') }}</x-ui.button>
                        </form>
                    </div>
                </div>
            </div>
        @empty
            <x-ui.empty icon="layers" :title="__('No instance yet')" :description="__('Instances appear here when their administrator connects to this relay.')" />
        @endforelse

        @if ($clients->hasPages())
            <div data-live-pages class="border-t border-zinc-100 px-6 py-4 dark:border-zinc-800">{{ $clients->links() }}</div>
        @endif
        </div>
    </div>
</x-layouts.app>
