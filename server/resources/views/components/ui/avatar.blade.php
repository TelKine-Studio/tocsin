@props(['user' => null, 'name' => null, 'size' => 'md'])
@php
    $label = $user?->name ?? $name ?? '?';
    $initials = $user?->initials() ?? collect(preg_split('/\s+/', trim($label)))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('');
    $palette = ['from-orange-400 to-rose-500', 'from-sky-400 to-indigo-500', 'from-emerald-400 to-teal-600', 'from-violet-400 to-fuchsia-500', 'from-amber-400 to-orange-600', 'from-cyan-400 to-blue-600'];
    $gradient = $palette[crc32($label) % count($palette)];
    $sizes = ['xs' => 'size-6 text-[10px]', 'sm' => 'size-8 text-xs', 'md' => 'size-9 text-xs', 'lg' => 'size-12 text-base'];
@endphp
<span {{ $attributes->merge(['class' => "inline-flex shrink-0 items-center justify-center rounded-full bg-linear-to-br font-semibold text-white ring-2 ring-white dark:ring-zinc-900 {$gradient} {$sizes[$size]}"]) }}>{{ $initials ?: '?' }}</span>
