{{-- Lock-screen style preview of a notification, bound to an Alpine "composer" scope when "live" is set. --}}
@props(['title' => null, 'subtitle' => null, 'body' => null, 'live' => false, 'icon' => null])
<div {{ $attributes->merge(['class' => 'rounded-[22px] border border-white/40 bg-white/75 p-3 shadow-xl shadow-black/10 backdrop-blur-xl dark:border-white/10 dark:bg-zinc-800/80']) }}>
    <div class="flex gap-3">
        @if ($icon)
            {{-- Topic logo, with the app icon in the corner, as iOS shows communication notifications. --}}
            <span class="relative size-9 shrink-0">
                <img src="{{ $icon }}" alt="" class="size-9 rounded-full object-cover">
                <x-ui.logo class="absolute -right-1 -bottom-1 size-4 rounded-[5px] ring-2 ring-white dark:ring-zinc-800" />
            </span>
        @else
            <x-ui.logo class="size-9 rounded-[10px]" />
        @endif
        <div class="min-w-0 flex-1">
            <div class="flex items-baseline justify-between gap-2">
                <p class="truncate text-[13px] font-semibold text-zinc-900 dark:text-white" @if ($live) x-text="previewTitle" @endif>{{ $title }}</p>
                <span class="shrink-0 text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('now') }}</span>
            </div>
            <p class="truncate text-[13px] font-medium text-zinc-700 dark:text-zinc-200" @if ($live) x-show="previewSubtitle" x-text="previewSubtitle" @endif>{{ $subtitle }}</p>
            <p class="line-clamp-3 text-[13px] leading-snug text-zinc-700 dark:text-zinc-300" @if ($live) x-text="body || @js(__('Your message will appear here.'))" @endif>{{ $body }}</p>
        </div>
    </div>
</div>
