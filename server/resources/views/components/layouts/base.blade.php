@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#fafafa" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#09090b" media="(prefers-color-scheme: dark)">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <script>{!! file_get_contents(resource_path('js/theme-init.js')) !!}</script>
    @isset($head){{ $head }}@endisset
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body {{ $attributes->merge(['class' => 'h-full']) }}>
    {{ $slot }}

    {{-- Toasts --}}
    <div x-data x-init="@if (session('status')) $store.toasts.push(@js(session('status'))) @endif @if ($errors->has('alert')) $store.toasts.push(@js($errors->first('alert')), 'error') @endif"
        class="pointer-events-none fixed inset-x-0 top-0 z-[60] flex flex-col items-center gap-2 p-4 sm:items-end sm:p-6" aria-live="polite">
        <template x-for="toast in $store.toasts.items" :key="toast.id">
            <div x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 -translate-y-2 sm:translate-y-0 sm:translate-x-4" x-transition:enter-end="opacity-100 translate-x-0"
                class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-2xl border border-zinc-200 bg-white/95 p-4 shadow-(--shadow-lift) backdrop-blur dark:border-zinc-800 dark:bg-zinc-900/95">
                <span class="mt-0.5 inline-flex size-6 shrink-0 items-center justify-center rounded-full"
                    :class="toast.type === 'error' ? 'bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-400' : 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400'">
                    <x-ui.icon name="check" class="size-3.5" x-show="toast.type !== 'error'" />
                    <x-ui.icon name="alert" class="size-3.5" x-show="toast.type === 'error'" />
                </span>
                <p class="flex-1 pt-0.5 text-sm font-medium text-zinc-800 dark:text-zinc-100" x-text="toast.message"></p>
                <button type="button" @click="$store.toasts.dismiss(toast.id)" class="rounded-md p-1 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200"><x-ui.icon name="x" class="size-4" /></button>
            </div>
        </template>
    </div>

    {{-- Confirmation dialog used by forms with data-confirm --}}
    <div x-data x-cloak x-show="$store.confirm.open" class="fixed inset-0 z-[55] flex items-end justify-center sm:items-center sm:p-6" role="alertdialog" aria-modal="true" @keydown.escape.window="$store.confirm.open = false">
        <div x-show="$store.confirm.open" x-transition.opacity class="absolute inset-0 bg-zinc-950/40 backdrop-blur-sm" @click="$store.confirm.open = false"></div>
        <div x-show="$store.confirm.open" x-trap.noscroll="$store.confirm.open"
            x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-6 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
            class="relative w-full max-w-md rounded-t-3xl border border-zinc-200 bg-white p-6 shadow-2xl sm:rounded-2xl dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex gap-4">
                <span class="inline-flex size-11 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-400">
                    <x-ui.icon name="alert" class="size-5" />
                </span>
                <div class="pt-1">
                    <h2 class="text-base font-semibold text-zinc-900 dark:text-white" x-text="$store.confirm.title"></h2>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400" x-show="$store.confirm.message" x-text="$store.confirm.message"></p>
                </div>
            </div>
            <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <x-ui.button type="button" variant="secondary" @click="$store.confirm.open = false">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="button" variant="danger" @click="$store.confirm.accept()"><span x-text="$store.confirm.action || @js(__('Confirm'))"></span></x-ui.button>
            </div>
        </div>
    </div>
</body>
</html>
