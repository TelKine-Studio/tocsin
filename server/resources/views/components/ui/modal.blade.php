@props(['name', 'title' => null, 'description' => null, 'maxWidth' => 'max-w-lg', 'open' => false])
<div x-data="{ open: {{ $open ? 'true' : 'false' }} }"
    x-on:open-modal.window="if ($event.detail === @js($name)) open = true"
    x-on:close-modal.window="if ($event.detail === @js($name)) open = false"
    x-on:keydown.escape.window="open = false">
    <template x-teleport="body">
        <div x-cloak x-show="open" class="fixed inset-0 z-50 flex items-end justify-center p-0 sm:items-center sm:p-6" role="dialog" aria-modal="true">
            <div x-show="open" x-transition.opacity @click="open = false" class="absolute inset-0 bg-zinc-950/40 backdrop-blur-sm"></div>
            <div x-show="open" x-trap.noscroll="open"
                x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-6 sm:translate-y-2 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0 translate-y-6 sm:translate-y-2 sm:scale-95"
                class="relative w-full {{ $maxWidth }} rounded-t-3xl border border-zinc-200 bg-white p-6 shadow-2xl sm:rounded-2xl dark:border-zinc-800 dark:bg-zinc-900">
                <button type="button" @click="open = false" class="absolute top-4 right-4 rounded-lg p-1.5 text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-white/5 dark:hover:text-zinc-200">
                    <x-ui.icon name="x" class="size-4" /><span class="sr-only">{{ __('Close') }}</span>
                </button>
                @if ($title)
                    <h2 class="pr-8 text-lg font-semibold text-zinc-900 dark:text-white">{{ $title }}</h2>
                    @if ($description)<p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ $description }}</p>@endif
                    <div class="mt-5">{{ $slot }}</div>
                @else
                    {{ $slot }}
                @endif
            </div>
        </div>
    </template>
</div>
