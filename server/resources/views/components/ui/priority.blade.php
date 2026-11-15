@props(['priority'])
@php
    $value = $priority instanceof \App\Enums\Priority ? $priority : \App\Enums\Priority::from((int) $priority);
    $color = match ($value->value) { 5 => 'red', 4 => 'amber', 1, 2 => 'zinc', default => 'blue' };
@endphp
<x-ui.badge :color="$color" dot {{ $attributes }}>{{ $value->label() }}</x-ui.badge>
