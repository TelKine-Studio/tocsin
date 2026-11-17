@props(['title' => null, 'description' => null, 'padding' => true, 'icon' => null])
<section {{ $attributes->merge(['class' => 'card']) }}>
    @if ($title || isset($actions))
        <header class="flex flex-col gap-3 px-5 pt-5 sm:flex-row sm:items-start sm:justify-between sm:gap-4 sm:px-6">
            <div class="flex min-w-0 flex-1 items-start gap-3">
                @if ($icon)
                    <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-xl bg-zinc-100 text-zinc-600 dark:bg-white/5 dark:text-zinc-300">
                        <x-ui.icon :name="$icon" class="size-[18px]" />
                    </span>
                @endif
                <div class="min-w-0">
                    <h2 class="text-[15px] font-semibold text-zinc-900 dark:text-zinc-50">{{ $title }}</h2>
                    @if ($description)
                        <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">{{ $description }}</p>
                    @endif
                </div>
            </div>
            @isset($actions)
                <div @class(['flex shrink-0 items-center gap-2', 'pl-12 sm:pl-0' => $icon])>{{ $actions }}</div>
            @endisset
        </header>
    @endif
    <div @class(['px-5 py-5 sm:px-6' => $padding, 'pt-4' => $padding && $title, 'mt-4' => ! $padding && $title])>
        {{ $slot }}
    </div>
    @isset($footer)
        <footer class="flex flex-wrap items-center justify-end gap-3 rounded-b-2xl border-t border-zinc-100 bg-zinc-50/70 px-5 py-3.5 sm:px-6 dark:border-zinc-800 dark:bg-white/[.02]">
            {{ $footer }}
        </footer>
    @endisset
</section>
