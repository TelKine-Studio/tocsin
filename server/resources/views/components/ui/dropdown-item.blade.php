@props(['href' => null, 'icon' => null, 'danger' => false])
@php $classes = 'flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-sm transition '.($danger ? 'text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10' : 'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5'); @endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>@if ($icon)<x-ui.icon :name="$icon" class="size-4 opacity-70" />@endif{{ $slot }}</a>
@else
    <button type="submit" {{ $attributes->merge(['class' => $classes]) }}>@if ($icon)<x-ui.icon :name="$icon" class="size-4 opacity-70" />@endif{{ $slot }}</button>
@endif
