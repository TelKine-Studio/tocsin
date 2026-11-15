@props(['name', 'label' => null, 'hint' => null, 'value' => null, 'rows' => 3, 'optional' => false])
<x-ui.field :name="$name" :label="$label" :hint="$hint" :optional="$optional" :class="$attributes->get('class')">
    <textarea id="{{ $name }}" name="{{ $name }}" rows="{{ $rows }}"
        {{ $attributes->except('class')->class(['form-control resize-y', 'form-control-invalid' => $errors->has($name)]) }}>{{ old($name, $value) }}</textarea>
</x-ui.field>
