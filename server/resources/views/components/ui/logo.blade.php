@props(['class' => 'size-8'])
<span {{ $attributes->merge(['class' => "inline-flex shrink-0 items-center justify-center rounded-xl bg-linear-to-br from-brand-400 to-brand-700 text-white shadow-sm shadow-brand-700/30 ring-1 ring-inset ring-white/20 $class"]) }}>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" class="size-[58%]" aria-hidden="true">
        <path d="M10.268 21a2 2 0 0 0 3.464 0"/>
        <path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/>
        <path d="M2 4.5c.6-1 1.4-1.8 2.3-2.4"/>
        <path d="M22 4.5c-.6-1-1.4-1.8-2.3-2.4"/>
    </svg>
</span>
