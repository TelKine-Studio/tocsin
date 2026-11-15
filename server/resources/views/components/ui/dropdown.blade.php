@props(['align' => 'right', 'width' => 'w-56'])
<div x-data="{ open: false }" @keydown.escape.window="open = false" @click.outside="open = false" {{ $attributes->merge(['class' => 'relative']) }}>
    <div @click="open = ! open">{{ $trigger }}</div>
    <div x-cloak x-show="open"
        x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 scale-95 -translate-y-1" x-transition:enter-end="opacity-100 scale-100 translate-y-0"
        x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0 scale-95"
        @class([
            'absolute z-40 mt-2 origin-top rounded-xl border border-zinc-200 bg-white p-1.5 shadow-(--shadow-lift) dark:border-zinc-800 dark:bg-zinc-900',
            $width,
            'right-0' => $align === 'right',
            'left-0' => $align === 'left',
            'bottom-full mb-2 origin-bottom' => $align === 'top',
        ])>
        {{ $slot }}
    </div>
</div>
