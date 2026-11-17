@props(['label' => null])
<div x-data="copy" {{ $attributes->merge(['class' => 'group relative overflow-hidden rounded-xl border border-zinc-800 bg-zinc-950 shadow-sm']) }}>
    <div class="flex items-center justify-between border-b border-white/10 px-4 py-2">
        <span class="flex items-center gap-2 text-xs font-medium text-zinc-400">
            <span class="flex gap-1"><span class="size-2 rounded-full bg-zinc-700"></span><span class="size-2 rounded-full bg-zinc-700"></span><span class="size-2 rounded-full bg-zinc-700"></span></span>
            {{ $label }}
        </span>
        <button type="button" @click="copy()" class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium text-zinc-400 transition hover:bg-white/10 hover:text-white">
            <x-ui.icon name="copy" class="size-3.5" x-show="!copied" />
            <x-ui.icon name="check" class="size-3.5 text-emerald-400" x-show="copied" x-cloak />
            <span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span>
        </button>
    </div>
    <pre class="scrollbar-none overflow-x-auto p-4 font-mono text-[12.5px] leading-relaxed text-zinc-100"><code x-ref="source">{{ $slot }}</code></pre>
</div>
