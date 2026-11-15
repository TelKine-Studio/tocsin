@props(['name', 'label' => null, 'hint' => null, 'for' => null, 'optional' => false])
<div {{ $attributes->merge(['class' => 'space-y-1.5']) }}>
    @if ($label)
        <label for="{{ $for ?? $name }}" class="flex items-center justify-between text-sm font-medium text-zinc-800 dark:text-zinc-200">
            <span>{{ $label }}</span>
            @if ($optional)<span class="text-xs font-normal text-zinc-400">{{ __('Optional') }}</span>@endif
        </label>
    @endif
    {{ $slot }}
    @error($name)
        <p class="flex items-center gap-1.5 text-sm text-red-600 dark:text-red-400"><x-ui.icon name="alert" class="size-3.5 shrink-0" />{{ $message }}</p>
    @elseif ($hint)
        <p class="text-xs leading-relaxed text-zinc-500 dark:text-zinc-400">{{ $hint }}</p>
    @enderror
</div>
