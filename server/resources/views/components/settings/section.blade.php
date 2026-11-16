@props([
    'title' => null,
    'description' => null,
    'icon' => null,
    // Field names: the section opens by itself when one of them has an error.
    'fields' => [],
    'open' => null,
    'editable' => true,
    // Nothing configured yet: the button reads "Set up".
    'empty' => false,
    'editLabel' => null,
    'saveLabel' => null,
])
@php
    $open ??= $editable && $fields !== [] && $errors->hasAny($fields);
@endphp
{{-- Settings card shown as a summary; "Edit" reveals the form fields and its own Save/Cancel. --}}
<section {{ $attributes->merge(['class' => 'card']) }} x-data="settingsSection(@js((bool) $open))">
    <header class="flex flex-col gap-3 px-5 pt-5 sm:flex-row sm:items-start sm:justify-between sm:gap-4 sm:px-6">
        <div class="flex min-w-0 flex-1 items-start gap-3">
            @if ($icon)
                <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-xl bg-zinc-100 text-zinc-600 dark:bg-white/5 dark:text-zinc-300">
                    <x-ui.icon :name="$icon" class="size-[18px]" />
                </span>
            @endif
            <div class="min-w-0">
                <h2 class="text-[15px] font-semibold text-zinc-900 dark:text-zinc-50">{{ $title }}</h2>
                @if ($description)
                    <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">{{ $description }}</p>
                @endif
            </div>
        </div>
        @if (isset($status) || $editable)
            <div @class(['flex shrink-0 items-center gap-2', 'pl-12 sm:pl-0' => $icon])>
                {{ $status ?? '' }}
                @if ($editable)
                    <x-ui.button type="button" variant="secondary" size="sm" :icon="$empty ? 'plus' : 'pencil'" x-show="! editing" x-on:click="edit()">
                        {{ $editLabel ?? ($empty ? __('Set up') : __('Edit')) }}
                    </x-ui.button>
                @endif
            </div>
        @endif
    </header>

    <div class="px-5 pt-4 pb-5 sm:px-6">
        @isset($summary)
            <div x-show="! editing" @if ($open) x-cloak @endif>{{ $summary }}</div>
        @endisset
        <div x-ref="fields" x-show="editing" @unless ($open) x-cloak @endunless>{{ $slot }}</div>
    </div>

    @if ($editable)
        <footer x-show="editing" @unless ($open) x-cloak @endunless
            class="flex flex-wrap items-center justify-end gap-3 rounded-b-2xl border-t border-zinc-100 bg-zinc-50/70 px-5 py-3.5 sm:px-6 dark:border-zinc-800 dark:bg-white/[.02]">
            {{ $danger ?? '' }}
            <x-ui.button type="button" variant="ghost" x-on:click="cancel()">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button>{{ $saveLabel ?? __('Save') }}</x-ui.button>
        </footer>
    @endif
</section>
