@props(['color' => 'zinc', 'icon' => null, 'dot' => false])
@php
    $colors = [
        'zinc' => 'bg-zinc-100 text-zinc-700 dark:bg-white/5 dark:text-zinc-300',
        'brand' => 'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300',
        'green' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
        'red' => 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300',
        'blue' => 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
        'amber' => 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300',
        'violet' => 'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300',
    ];
    $dots = ['zinc' => 'bg-zinc-400', 'brand' => 'bg-brand-500', 'green' => 'bg-emerald-500', 'red' => 'bg-red-500', 'blue' => 'bg-sky-500', 'amber' => 'bg-amber-500', 'violet' => 'bg-violet-500'];
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-xs font-medium whitespace-nowrap '.$colors[$color]]) }}>
    @if ($dot)<span class="size-1.5 rounded-full {{ $dots[$color] }}"></span>@endif
    @if ($icon)<x-ui.icon :name="$icon" class="size-3" />@endif
    {{ $slot }}
</span>
