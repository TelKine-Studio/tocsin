@props(['value' => null, 'label' => null])
{{-- Change against the previous period, as OneSignal: green up, red down. --}}
@if ($value !== null)
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 text-xs']) }}>
        <span @class(['inline-flex items-center gap-0.5 font-medium tabular-nums', 'text-emerald-600 dark:text-emerald-400' => $value >= 0, 'text-red-600 dark:text-red-400' => $value < 0])>
            <svg viewBox="0 0 12 12" class="size-3" aria-hidden="true"><path d="{{ $value >= 0 ? 'M2 9l3.5-3.5 2 2L10 4M7 4h3v3' : 'M2 3l3.5 3.5 2-2L10 8M7 8h3V5' }}" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            {{ ($value >= 0 ? '+' : '').number_format($value, 1, ',', ' ') }} %
        </span>
        @if ($label)<span class="text-zinc-500 dark:text-zinc-400">{{ $label }}</span>@endif
    </span>
@endif
