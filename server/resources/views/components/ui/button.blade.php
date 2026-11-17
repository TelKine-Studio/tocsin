@props(['variant' => 'primary', 'size' => 'md', 'href' => null, 'icon' => null, 'iconRight' => null, 'type' => 'submit'])
@php
    // Thin like OneSignal: 32px high, 14px text, 4px radius, 1px border, no shadow.
    $base = 'inline-flex items-center justify-center gap-1.5 rounded-md border font-medium whitespace-nowrap transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1 focus-visible:ring-offset-white dark:focus-visible:ring-offset-zinc-950 disabled:pointer-events-none disabled:opacity-50';
    $sizes = [
        'xs' => 'h-6 px-2 text-xs',
        'sm' => 'h-7 px-2.5 text-[13px]',
        'md' => 'h-8 px-3 text-sm',
        'lg' => 'h-9 px-4 text-sm',
        'icon' => 'size-8 text-sm',
    ];
    $variants = [
        'primary' => 'border-brand-600 bg-brand-600 text-white hover:border-brand-700 hover:bg-brand-700 focus-visible:ring-brand-500/40',
        'dark' => 'border-zinc-900 bg-zinc-900 text-white hover:bg-zinc-800 focus-visible:ring-zinc-500/40 dark:border-white dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200',
        'secondary' => 'border-zinc-300 bg-white text-zinc-800 hover:border-zinc-400 hover:bg-zinc-50 focus-visible:ring-zinc-400/40 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:border-zinc-600 dark:hover:bg-zinc-800',
        'danger' => 'border-red-600 bg-red-600 text-white hover:border-red-700 hover:bg-red-700 focus-visible:ring-red-500/40',
        'ghost' => 'border-transparent text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 focus-visible:ring-zinc-400/40 dark:text-zinc-400 dark:hover:bg-white/5 dark:hover:text-zinc-100',
        'ghost-danger' => 'border-transparent text-red-600 hover:bg-red-50 focus-visible:ring-red-500/40 dark:text-red-400 dark:hover:bg-red-500/10',
    ];
    $classes = $base.' '.$sizes[$size].' '.$variants[$variant];
    $iconSize = $size === 'lg' ? 'size-4' : 'size-3.5';
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-ui.icon :name="$icon" :class="$iconSize" />@endif
        {{ $slot }}
        @if ($iconRight)<x-ui.icon :name="$iconRight" :class="$iconSize" />@endif
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-ui.icon :name="$icon" :class="$iconSize" />@endif
        {{ $slot }}
        @if ($iconRight)<x-ui.icon :name="$iconRight" :class="$iconSize" />@endif
    </button>
@endif
