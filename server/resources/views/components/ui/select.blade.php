@props(['name', 'label' => null, 'hint' => null, 'options' => [], 'value' => null, 'size' => 'md'])
@php
    $selected = (string) old($name, $value);
    $current = $options[$selected] ?? (count($options) ? reset($options) : '');
    $id = $attributes->get('id', $name);
@endphp
{{-- Custom dropdown. A hidden native <select> keeps form posts, x-model and onchange working. --}}
<x-ui.field :name="$name" :label="$label" :hint="$hint" :for="$id" :class="$attributes->get('class')">
    <div x-data="selectBox" class="relative" @click.outside="close()" @keydown.escape.stop="close()">
        <select x-ref="native" name="{{ $name }}" class="sr-only" tabindex="-1" aria-hidden="true" @change="sync()"
            {{ $attributes->except(['id', 'class']) }}>
            @foreach ($options as $optionValue => $optionLabel)
                <option value="{{ $optionValue }}" @selected($selected === (string) $optionValue)>{{ $optionLabel }}</option>
            @endforeach
        </select>
        <button type="button" id="{{ $id }}" x-ref="button" @click="toggle()" @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.enter.prevent="pick()" @keydown.space.prevent="pick()"
            aria-haspopup="listbox" :aria-expanded="open.toString()"
            @class(['form-control flex items-center gap-2 pr-10 text-left', '!pr-8 text-[13px]' => $size === 'sm', 'form-control-invalid' => $errors->has($name)])
            :class="open && '!border-brand-500 ring-2 ring-brand-500/20'">
            <span class="min-w-0 flex-1 truncate" x-text="label">{{ $current }}</span>
            <x-ui.icon name="chevron-down" class="pointer-events-none absolute top-1/2 {{ $size === 'sm' ? 'right-2.5 size-3.5' : 'right-3.5 size-4' }} -translate-y-1/2 text-zinc-400 transition" ::class="open && 'rotate-180'" />
        </button>
        <ul x-show="open" x-cloak x-ref="list" role="listbox"
            x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-75" x-transition:leave-end="opacity-0"
            class="absolute z-40 mt-1.5 max-h-64 w-full min-w-48 overflow-auto rounded-xl border border-zinc-200 bg-white p-1 shadow-(--shadow-lift) dark:border-zinc-800 dark:bg-zinc-900">
            <template x-for="(option, index) in options" :key="option.value">
                <li role="option" :aria-selected="(option.value === value).toString()" @click="choose(option.value)" @mouseenter="active = index"
                    class="flex cursor-pointer items-center gap-3 rounded-lg px-3 py-2 text-sm text-zinc-700 dark:text-zinc-200"
                    :class="{ 'bg-zinc-100 dark:bg-white/5': active === index, 'font-semibold text-zinc-900 dark:text-white': option.value === value }">
                    <span class="min-w-0 flex-1" x-text="option.label"></span>
                    <x-ui.icon name="check" class="size-4 shrink-0 text-brand-600" x-show="option.value === value" />
                </li>
            </template>
        </ul>
    </div>
</x-ui.field>
