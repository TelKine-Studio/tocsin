@props(['columns' => 2])
{{-- Read-only list of the current values of a settings section. --}}
<dl {{ $attributes->merge(['class' => 'grid gap-x-6 gap-y-4 text-sm '.($columns > 1 ? 'sm:grid-cols-2' : '')]) }}>
    {{ $slot }}
</dl>
