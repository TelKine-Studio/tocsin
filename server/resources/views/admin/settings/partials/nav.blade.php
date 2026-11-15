@php
    $tabs = [
        'general' => ['icon' => 'settings', 'label' => __('General'), 'text' => __('Name, URL, access')],
        'security' => ['icon' => 'shield', 'label' => __('Security'), 'text' => __('2FA, single sign-on')],
        'push' => ['icon' => 'apple', 'label' => __('Push notifications'), 'text' => __('Relay or APNs key')],
        'mail' => ['icon' => 'mail', 'label' => __('Email'), 'text' => __('Outgoing mail server')],
        'realtime' => ['icon' => 'radio', 'label' => __('Realtime'), 'text' => __('WebSocket server')],
        'relay' => ['icon' => 'layers', 'label' => __('Relay'), 'text' => __('Serve other instances')],
    ];
    // Selling the relay is reserved to the instance operated by Telkine.
    if (! config('tocsin.relay_operator')) {
        unset($tabs['relay']);
    }
@endphp
<x-ui.page-header :title="__('Settings')" :description="__('Everything is configured here: no configuration file to edit, no restart needed.')" />
<nav class="-mx-4 mb-6 flex gap-2 overflow-x-auto px-4 pb-1 lg:hidden">
    @foreach ($tabs as $key => $tab)
        <a href="{{ route('admin.settings.edit', $key) }}" @class(['inline-flex shrink-0 items-center gap-2 rounded-xl px-3.5 py-2 text-sm font-medium transition', 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $section === $key, 'bg-white text-zinc-600 ring-1 ring-zinc-200 dark:bg-zinc-900 dark:text-zinc-300 dark:ring-zinc-800' => $section !== $key])>
            <x-ui.icon :name="$tab['icon']" class="size-4" />{{ $tab['label'] }}
        </a>
    @endforeach
</nav>
