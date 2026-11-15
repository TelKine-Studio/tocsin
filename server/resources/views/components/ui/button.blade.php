@props(['variant' => 'primary', 'size' => 'md', 'href' => null, 'icon' => null, 'iconRight' => null, 'type' => 'submit'])
@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-xl font-semibold whitespace-nowrap transition duration-150 focus:outline-none focus-visible:ring-4 disabled:pointer-events-none disabled:opacity-50 active:scale-[.98]';
    $sizes = [
        'xs' => 'px-2 py-1 text-xs rounded-lg',
        'sm' => 'px-3 py-1.5 text-xs',
        'md' => 'px-4 py-2.5 text-sm',
        'lg' => 'px-5 py-3 text-sm',
        'icon' => 'size-9 text-sm',
    ];
    $variants = [
        'primary' => 'bg-brand-600 text-white shadow-sm shadow-brand-600/25 hover:bg-brand-700 focus-visible:ring-brand-500/30',
        'dark' => 'bg-zinc-900 text-white shadow-sm hover:bg-zinc-800 focus-visible:ring-zinc-500/30 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200',
        'secondary' => 'border border-zinc-200 bg-white text-zinc-700 shadow-xs hover:border-zinc-300 hover:bg-zinc-50 focus-visible:ring-zinc-400/20 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:border-zinc-700 dark:hover:bg-zinc-800',
        'danger' => 'bg-red-600 text-white shadow-sm shadow-red-600/20 hover:bg-red-700 focus-visible:ring-red-500/30',
        'ghost' => 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 focus-visible:ring-zinc-400/20 dark:text-zinc-400 dark:hover:bg-white/5 dark:hover:text-zinc-100',
        'ghost-danger' => 'text-red-600 hover:bg-red-50 focus-visible:ring-red-500/20 dark:text-red-400 dark:hover:bg-red-500/10',
    ];
    $classes = $base.' '.$sizes[$size].' '.$variants[$variant];
    $iconSize = in_array($size, ['xs', 'sm']) ? 'size-3.5' : 'size-4';
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
