{{-- Error pages: standalone (no session, no menu), so they render for any route. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title>@yield('title') · {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <script>{!! file_get_contents(resource_path('js/theme-init.js')) !!}</script>
    @vite(['resources/css/app.css'])
</head>
<body class="h-full bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-950 dark:text-white">
    <main class="relative isolate flex min-h-full items-center justify-center overflow-hidden px-6 py-16">
        <div class="absolute inset-0 -z-10 bg-[radial-gradient(ellipse_at_top,var(--color-brand-500)/0.12,transparent_60%)]"></div>
        <div class="dot-grid absolute inset-0 -z-10 opacity-60 [mask-image:radial-gradient(ellipse_at_center,black,transparent_70%)]"></div>

        <div class="w-full max-w-md text-center">
            <a href="{{ url('/') }}" class="inline-flex items-center gap-2.5">
                <x-ui.logo class="size-9" />
                <span class="text-lg font-semibold tracking-tight">{{ config('app.name') }}</span>
            </a>

            {{-- The error, as a notification. --}}
            <div class="mx-auto mt-10 max-w-sm rounded-[22px] border border-zinc-200/80 bg-white/90 p-3.5 text-left shadow-(--shadow-lift) backdrop-blur dark:border-white/10 dark:bg-zinc-900/90">
                <div class="flex gap-3">
                    <span class="grid size-9 shrink-0 place-items-center rounded-[10px] bg-zinc-100 text-zinc-500 dark:bg-white/10 dark:text-zinc-300">
                        <x-ui.icon :name="trim($__env->yieldContent('icon', 'alert'))" class="size-[18px]" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline justify-between gap-2">
                            <p class="truncate text-[13px] font-semibold">{{ __('Error :code', ['code' => trim($__env->yieldContent('code'))]) }}</p>
                            <span class="text-[11px] text-zinc-400">{{ __('now') }}</span>
                        </div>
                        <p class="text-[13px] text-zinc-600 dark:text-zinc-300">@yield('title')</p>
                    </div>
                </div>
            </div>

            <h1 class="mt-8 text-3xl font-semibold tracking-tight text-balance">@yield('title')</h1>
            <p class="mt-3 text-[15px] leading-relaxed text-pretty text-zinc-500 dark:text-zinc-400">@yield('message')</p>

            <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
                @hasSection('action')
                    @yield('action')
                @else
                    <x-ui.button :href="url('/')" icon="home">{{ __('Back to home') }}</x-ui.button>
                @endif
                <x-ui.button variant="secondary" type="button" icon="arrow-left" onclick="history.length > 1 ? history.back() : location.assign('/')">{{ __('Go back') }}</x-ui.button>
            </div>
        </div>
    </main>
</body>
</html>
