@props(['title' => null, 'heading' => null, 'subheading' => null, 'wide' => false])
<x-layouts.base :title="$title" class="bg-white dark:bg-zinc-950">
    <div class="flex min-h-full">
        {{-- Brand panel --}}
        <aside class="relative hidden w-[44%] max-w-2xl overflow-hidden bg-zinc-950 lg:flex lg:flex-col">
            <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_top_left,var(--color-brand-600),transparent_55%),radial-gradient(ellipse_at_bottom_right,#7c3aed55,transparent_50%)] opacity-90"></div>
            <div class="dot-grid absolute inset-0 [mask-image:linear-gradient(to_bottom,black,transparent)]"></div>

            <div class="relative flex flex-1 flex-col justify-between p-12 xl:p-16">
                <a href="{{ url('/') }}" class="flex items-center gap-3 text-white">
                    <x-ui.logo class="size-10" />
                    <span class="text-lg font-semibold tracking-tight">{{ config('app.name') }}</span>
                </a>

                <div class="space-y-4">
                    @foreach ([
                        ['title' => __('Backup completed'), 'sub' => __('Server backups'), 'body' => __('Nightly backup finished in 42 seconds.'), 'time' => __('now')],
                        ['title' => __('Disk almost full'), 'sub' => __('Monitoring'), 'body' => __('/var is 92% full on web-1.'), 'time' => '2 min'],
                        ['title' => __('Deployment succeeded'), 'sub' => __('Production'), 'body' => __('Version 1.4.2 is live.'), 'time' => '12 min'],
                    ] as $index => $card)
                        <div class="max-w-sm rounded-[22px] border border-white/15 bg-white/10 p-3.5 text-white shadow-2xl shadow-black/30 backdrop-blur-xl" style="margin-left: {{ $index * 2.5 }}rem; opacity: {{ 1 - $index * 0.18 }}">
                            <div class="flex gap-3">
                                <x-ui.logo class="size-9 rounded-[10px]" />
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-baseline justify-between gap-2">
                                        <p class="truncate text-[13px] font-semibold">{{ $card['title'] }}</p>
                                        <span class="text-[11px] text-white/60">{{ $card['time'] }}</span>
                                    </div>
                                    <p class="text-[13px] font-medium text-white/80">{{ $card['sub'] }}</p>
                                    <p class="text-[13px] text-white/70">{{ $card['body'] }}</p>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div>
                    <h2 class="max-w-md text-3xl font-semibold tracking-tight text-white xl:text-4xl">{{ __('Your notifications, on your server.') }}</h2>
                    <p class="mt-4 max-w-md text-base leading-relaxed text-white/70">{{ __('Publish from any script with a single HTTP request and get instant push notifications on your iPhone.') }}</p>
                </div>
            </div>
        </aside>

        {{-- Form --}}
        <main class="flex flex-1 flex-col">
            <div class="flex flex-1 flex-col justify-center px-5 py-12 sm:px-10">
                <div @class(['mx-auto w-full animate-slide-up', 'max-w-sm' => ! $wide, 'max-w-xl' => $wide])>
                    <a href="{{ url('/') }}" class="mb-10 inline-flex items-center gap-3 lg:hidden">
                        <x-ui.logo class="size-10" />
                        <span class="text-lg font-semibold tracking-tight">{{ config('app.name') }}</span>
                    </a>

                    @if ($heading)
                        <div class="mb-8">
                            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">{{ $heading }}</h1>
                            @if ($subheading)
                                <p class="mt-2 text-sm leading-relaxed text-zinc-500 dark:text-zinc-400">{{ $subheading }}</p>
                            @endif
                        </div>
                    @endif

                    {{ $slot }}

                    @isset($below)
                        <div class="mt-8 text-sm text-zinc-500 dark:text-zinc-400">{{ $below }}</div>
                    @endisset
                </div>
            </div>

            <footer class="flex items-center justify-between px-5 py-6 text-xs text-zinc-400 sm:px-10">
                <span><a href="{{ config('tocsin.repository') }}" class="font-medium text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200">Tocsin</a> · {{ __('a project by') }} <a href="{{ config('tocsin.publisher_url') }}" class="font-medium text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200">{{ config('tocsin.publisher') }}</a></span>
                <div x-data class="flex gap-1">
                    @foreach (['light' => 'sun', 'dark' => 'moon', 'system' => 'monitor'] as $theme => $icon)
                        <button type="button" @click="$store.theme.set('{{ $theme }}')" class="rounded-md p-1.5 transition hover:text-zinc-700 dark:hover:text-zinc-200" :class="$store.theme.value === '{{ $theme }}' && 'text-zinc-800 dark:text-white'">
                            <x-ui.icon :name="$icon" class="size-3.5" /><span class="sr-only">{{ __(ucfirst($theme)) }}</span>
                        </button>
                    @endforeach
                </div>
            </footer>
        </main>
    </div>
</x-layouts.base>
