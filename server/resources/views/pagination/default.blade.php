@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination') }}" class="flex items-center justify-between gap-3">
        <p class="text-sm text-zinc-500 dark:text-zinc-400">
            @if (method_exists($paginator, 'total'))
                {{ __('Showing :from–:to of :total', ['from' => $paginator->firstItem(), 'to' => $paginator->lastItem(), 'total' => $paginator->total()]) }}
            @endif
        </p>
        <div class="flex gap-2">
            @if ($paginator->onFirstPage())
                <span class="inline-flex h-8 items-center rounded-md border border-zinc-200 px-3 text-sm text-zinc-300 dark:border-zinc-800 dark:text-zinc-600">{{ __('Previous') }}</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="inline-flex h-8 items-center rounded-md border border-zinc-300 bg-white px-3 text-sm font-medium text-zinc-800 hover:border-zinc-400 hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800">{{ __('Previous') }}</a>
            @endif
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="inline-flex h-8 items-center rounded-md border border-zinc-300 bg-white px-3 text-sm font-medium text-zinc-800 hover:border-zinc-400 hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800">{{ __('Next') }}</a>
            @else
                <span class="inline-flex h-8 items-center rounded-md border border-zinc-200 px-3 text-sm text-zinc-300 dark:border-zinc-800 dark:text-zinc-600">{{ __('Next') }}</span>
            @endif
        </div>
    </nav>
@endif
