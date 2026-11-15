@props(['title', 'description' => null])
<div class="mb-8 flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0">
        @isset($breadcrumb)
            <nav class="mb-2 flex items-center gap-1.5 text-sm text-zinc-500 dark:text-zinc-400">{{ $breadcrumb }}</nav>
        @endisset
        <h1 class="truncate text-2xl font-semibold tracking-tight text-zinc-900 sm:text-[28px] dark:text-white">{{ $title }}</h1>
        @if ($description)
            <p class="mt-1.5 max-w-2xl text-sm text-zinc-500 dark:text-zinc-400">{{ $description }}</p>
        @endif
    </div>
    @if ($slot->isNotEmpty())
        <div class="flex flex-wrap items-center gap-2">{{ $slot }}</div>
    @endif
</div>
