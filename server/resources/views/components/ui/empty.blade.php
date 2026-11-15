@props(['icon' => 'inbox', 'title', 'description' => null])
<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center px-6 py-14 text-center']) }}>
    <div class="relative mb-5">
        <span class="absolute inset-0 -m-3 rounded-full bg-brand-500/10 blur-xl"></span>
        <span class="relative inline-flex size-14 items-center justify-center rounded-2xl border border-zinc-200 bg-white text-brand-600 shadow-(--shadow-soft) dark:border-zinc-800 dark:bg-zinc-900 dark:text-brand-400">
            <x-ui.icon :name="$icon" class="size-6" />
        </span>
    </div>
    <h3 class="text-base font-semibold text-zinc-900 dark:text-zinc-100">{{ $title }}</h3>
    @if ($description)
        <p class="mt-1.5 max-w-sm text-sm leading-relaxed text-zinc-500 dark:text-zinc-400">{{ $description }}</p>
    @endif
    @if ($slot->isNotEmpty())
        <div class="mt-6 flex flex-wrap justify-center gap-2">{{ $slot }}</div>
    @endif
</div>
