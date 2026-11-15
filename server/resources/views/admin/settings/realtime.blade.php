<x-layouts.app :title="__('Settings')">
    @include('admin.settings.partials.nav')
    <x-settings.layout :section="$section">
        <x-ui.card :title="__('Realtime server')" :description="__('Keeps the web interface and the open iPhone app up to date instantly over WebSocket (Laravel Reverb). Push notifications go through Apple.')" icon="radio">
            <div x-data="liveTopic(null, false)" class="mb-6 flex items-center gap-3 rounded-xl border border-zinc-100 p-4 dark:border-zinc-800">
                <span class="relative flex size-3">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-60" x-show="state === 'connected'"></span>
                    <span class="relative inline-flex size-3 rounded-full" :class="state === 'connected' ? 'bg-emerald-500' : (state === 'connecting' ? 'bg-amber-400' : 'bg-red-500')"></span>
                </span>
                <span class="text-sm font-medium text-zinc-900 dark:text-white" x-text="state === 'connected' ? @js(__('Connected from this browser')) : (state === 'connecting' ? @js(__('Connecting…')) : @js(__('Unable to connect')))"></span>
            </div>
            <dl class="grid gap-5 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-medium text-zinc-500">{{ __('Endpoint') }}</dt>
                    <dd class="mt-1 font-mono text-xs break-all text-zinc-800 dark:text-zinc-200">{{ $realtime['scheme'] === 'https' ? 'wss' : 'ws' }}://{{ $realtime['host'] }}:{{ $realtime['port'] }}{{ $realtime['path'] }}/app/{{ $realtime['key'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-zinc-500">{{ __('Application key') }}</dt>
                    <dd class="mt-1 font-mono text-xs text-zinc-800 dark:text-zinc-200">{{ $realtime['key'] ?? '—' }}</dd>
                </div>
            </dl>
        </x-ui.card>

        <x-ui.card :title="__('API')" icon="code">
            <p class="mb-3 text-sm text-zinc-600 dark:text-zinc-400">{{ __('Instance description used by the mobile app:') }}</p>
            <x-ui.code label="GET">{{ route('api.v1.info') }}</x-ui.code>
        </x-ui.card>
    </x-settings.layout>
</x-layouts.app>
