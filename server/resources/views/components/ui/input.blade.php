@props(['name', 'label' => null, 'hint' => null, 'type' => 'text', 'value' => null, 'prefix' => null, 'icon' => null, 'optional' => false])
@php $id = $attributes->get('id', $name); @endphp
<x-ui.field :name="$name" :label="$label" :hint="$hint" :for="$id" :optional="$optional" :class="$attributes->get('class')">
    <div class="relative flex">
        @if ($prefix)
            <span class="inline-flex items-center rounded-l-xl border border-r-0 border-zinc-200 bg-zinc-50 px-3 font-mono text-sm text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/50 dark:text-zinc-400">{{ $prefix }}</span>
        @endif
        @if ($icon)
            <x-ui.icon :name="$icon" class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-zinc-400" />
        @endif
        <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" value="{{ $type === 'password' ? '' : old($name, $value) }}"
            {{ $attributes->except(['id', 'class'])->class(['form-control', 'rounded-l-none' => $prefix, 'pl-10' => $icon, 'form-control-invalid' => $errors->has($name)]) }}>
    </div>
</x-ui.field>
