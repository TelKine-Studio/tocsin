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
    if (! config('tocsin.relay_operator') && ! config('tocsin.hosted')) {
        unset($tabs['relay']);
    } elseif (! config('tocsin.relay_operator')) {
        $tabs['relay'] = ['icon' => 'sparkles', 'label' => __('Plans'), 'text' => __('Accounts and payments')];
    }
@endphp
<x-ui.page-header :title="__('Settings')" :description="__('Everything is configured here: no configuration file to edit, no restart needed.')" />
<x-settings.tabs :tabs="collect($tabs)->map(fn ($tab, $key) => [$tab['label'], route('admin.settings.edit', $key)])->all()" :current="$section" />
