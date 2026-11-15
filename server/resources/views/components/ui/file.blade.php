@props(['name', 'label' => null, 'hint' => null, 'optional' => false, 'placeholder' => null])
@php $id = $attributes->get('id', $name); @endphp
{{-- Custom file picker (click or drop), in place of the browser's "Choose file / No file chosen". --}}
<x-ui.field :name="$name" :label="$label" :hint="$hint" :for="$id" :optional="$optional" :class="$attributes->get('class')">
    <label for="{{ $id }}" x-data="{ file: null, over: false,
            pick(files) { this.file = files && files[0] ? { name: files[0].name, size: files[0].size } : null },
            clear() { this.$refs.input.value = ''; this.file = null },
            size(bytes) { return bytes < 1024 ? bytes + ' B' : bytes < 1048576 ? (bytes / 1024).toFixed(0) + ' KB' : (bytes / 1048576).toFixed(1) + ' MB' } }"
        @dragover.prevent="over = true" @dragleave.prevent="over = false"
        @drop.prevent="over = false; $refs.input.files = $event.dataTransfer.files; pick($event.dataTransfer.files)"
        @class(['flex cursor-pointer items-center gap-3 rounded-xl border border-dashed bg-white px-3.5 py-2.5 transition hover:border-brand-400 hover:bg-brand-50/30 dark:bg-zinc-900 dark:hover:bg-brand-500/5', 'border-red-400' => $errors->has($name), 'border-zinc-300 dark:border-zinc-700' => ! $errors->has($name)])
        :class="over && '!border-brand-500 bg-brand-50/50'">
        <input type="file" id="{{ $id }}" name="{{ $name }}" x-ref="input" class="sr-only" @change="pick($event.target.files)" {{ $attributes->except(['id', 'class']) }}>
        <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-500 dark:bg-white/5 dark:text-zinc-400">
            <x-ui.icon name="upload" class="size-4" />
        </span>
        <span class="min-w-0 flex-1">
            <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white" x-text="file ? file.name : @js($placeholder ?? __('Choose a file'))">{{ $placeholder ?? __('Choose a file') }}</span>
            <span class="block text-xs text-zinc-500" x-text="file ? size(file.size) : @js(__('or drop it here'))">{{ __('or drop it here') }}</span>
        </span>
        <button type="button" x-show="file" x-cloak @click.prevent="clear()" class="inline-flex size-8 items-center justify-center rounded-lg text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-white/10">
            <x-ui.icon name="x" class="size-4" /><span class="sr-only">{{ __('Remove') }}</span>
        </button>
    </label>
</x-ui.field>
