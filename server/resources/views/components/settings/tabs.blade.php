@props(['tabs', 'current'])
{{-- Underlined tabs of the settings pages: [key => [label, url]]. --}}
<nav class="scrollbar-none mb-6 flex gap-1 overflow-x-auto border-b border-zinc-200 dark:border-zinc-800" aria-label="{{ __('Settings') }}">
    @foreach ($tabs as $key => [$tabLabel, $url])
        <a href="{{ $url }}" @if ($current === $key) aria-current="page" @endif
            @class(['-mb-px inline-flex shrink-0 items-center border-b-2 px-2.5 py-2.5 text-sm font-medium whitespace-nowrap transition',
                'border-brand-600 text-zinc-900 dark:border-brand-400 dark:text-white' => $current === $key,
                'border-transparent text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white' => $current !== $key])>{{ $tabLabel }}</a>
    @endforeach
</nav>
