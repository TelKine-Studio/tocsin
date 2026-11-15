@props(['name', 'label', 'description' => null, 'checked' => false])
@php $isChecked = session()->hasOldInput() ? (bool) old($name) : $checked; @endphp
<label class="group flex cursor-pointer items-start justify-between gap-6">
    <span class="space-y-0.5">
        <span class="block text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ $label }}</span>
        @if ($description)
            <span class="block text-sm leading-relaxed text-zinc-500 dark:text-zinc-400">{{ $description }}</span>
        @endif
    </span>
    <span class="relative mt-0.5 inline-flex shrink-0">
        <input type="checkbox" name="{{ $name }}" value="1" class="peer sr-only" @checked($isChecked) {{ $attributes }}>
        <span class="h-6 w-11 rounded-full bg-zinc-200 transition peer-checked:bg-brand-600 peer-focus-visible:ring-4 peer-focus-visible:ring-brand-500/25 dark:bg-zinc-700"></span>
        <span class="absolute top-0.5 left-0.5 size-5 rounded-full bg-white shadow-sm ring-1 ring-black/5 transition peer-checked:translate-x-5"></span>
    </span>
</label>
