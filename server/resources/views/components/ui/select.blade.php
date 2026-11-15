@props(['name', 'label' => null, 'hint' => null, 'options' => [], 'value' => null])
<x-ui.field :name="$name" :label="$label" :hint="$hint" :class="$attributes->get('class')">
    <div class="relative">
        <select id="{{ $name }}" name="{{ $name }}" {{ $attributes->except('class')->class(['form-control appearance-none pr-10', 'form-control-invalid' => $errors->has($name)]) }}>
            @foreach ($options as $optionValue => $optionLabel)
                <option value="{{ $optionValue }}" @selected((string) old($name, $value) === (string) $optionValue)>{{ $optionLabel }}</option>
            @endforeach
        </select>
        <x-ui.icon name="chevrons-up-down" class="pointer-events-none absolute top-1/2 right-3.5 size-4 -translate-y-1/2 text-zinc-400" />
    </div>
</x-ui.field>
