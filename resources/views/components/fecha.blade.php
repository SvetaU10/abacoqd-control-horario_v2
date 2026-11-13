@props([
    'name',
    'id' => null,
    'value' => null,
    'min' => null,
    'max' => null,
    'required' => false,
])

@php
    $id = $id ?: $name;
    $iso = $value instanceof \Illuminate\Support\Carbon ? $value->toDateString() : (string) ($value ?? '');
    $visible = '';
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $partes)) {
        $visible = $partes[3].'/'.$partes[2].'/'.$partes[1];
    }
@endphp

<span class="campo-fecha" data-campo-fecha>
    <input
        {{ $attributes->merge([
            'id' => $id,
            'class' => 'campo-fecha-texto',
            'type' => 'text',
            'inputmode' => 'numeric',
            'autocomplete' => 'off',
            'placeholder' => 'dd/mm/aaaa',
            'maxlength' => '10',
            'value' => $visible,
            'data-fecha-texto' => true,
        ]) }}
        @if ($required) required @endif
        @if ($min) data-min="{{ $min }}" @endif
        @if ($max) data-max="{{ $max }}" @endif
    >
    <button class="campo-fecha-abre" type="button" data-fecha-abre aria-label="Abrir calendario">
        <x-icono nombre="calendar" />
    </button>
    <input
        class="campo-fecha-nativo"
        type="date"
        tabindex="-1"
        aria-hidden="true"
        value="{{ $iso }}"
        @if ($min) min="{{ $min }}" @endif
        @if ($max) max="{{ $max }}" @endif
        data-fecha-nativo
    >
    <input type="hidden" name="{{ $name }}" value="{{ $iso }}" data-fecha-iso>
</span>
