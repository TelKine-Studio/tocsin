@props(['label', 'value' => null, 'mono' => false, 'wide' => false, 'on' => null, 'empty' => null])
{{-- One value of a settings summary. "on" shows an enabled/disabled state. --}}
<div @class(['min-w-0', 'sm:col-span-2' => $wide])>
    <dt class="text-xs text-zinc-500">{{ $label }}</dt>
    <dd class="mt-1 text-zinc-900 dark:text-zinc-100">
        @if ($on !== null)
            <span class="inline-flex items-center gap-1.5">
                <span @class(['size-1.5 rounded-full', 'bg-emerald-500' => $on, 'bg-zinc-300 dark:bg-zinc-600' => ! $on])></span>
                {{ $on ? ($value ?? __('On')) : ($empty ?? __('Off')) }}
            </span>
        @elseif ($slot->isNotEmpty())
            {{ $slot }}
        @elseif ($value !== null && $value !== '')
            <span @class(['break-words', 'font-mono text-[13px]' => $mono])>{{ $value }}</span>
        @else
            <span class="text-zinc-400 dark:text-zinc-500">{{ $empty ?? __('Not set') }}</span>
        @endif
    </dd>
</div>
