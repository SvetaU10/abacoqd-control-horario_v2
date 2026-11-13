@props([
    'id',
    'name',
    'valor' => '',
    'min' => null,
    'max' => null,
    'required' => true,
])

@php
    $valor = is_string($valor) ? substr($valor, 0, 5) : '';
    [$horaElegida, $minutoElegido] = array_pad($valor !== '' ? explode(':', $valor) : ['', ''], 2, '');
@endphp

<div
    class="hora24"
    data-hora
    @if ($min) data-min="{{ substr($min, 0, 5) }}" @endif
    @if ($max) data-max="{{ substr($max, 0, 5) }}" @endif
>
    <select id="{{ $id }}" data-parte="h" aria-label="Hora, de 00 a 23" @if ($required) required @endif>
        <option value="">hh</option>
        @for ($hora = 0; $hora < 24; $hora++)
            @php $texto = sprintf('%02d', $hora); @endphp
            <option value="{{ $texto }}" @selected($horaElegida === $texto)>{{ $texto }}</option>
        @endfor
    </select>
    <span class="hora24-sep" aria-hidden="true">:</span>
    <select id="{{ $id }}-min" data-parte="m" aria-label="Minutos" @if ($required) required @endif>
        <option value="">mm</option>
        @for ($minuto = 0; $minuto < 60; $minuto++)
            @php $texto = sprintf('%02d', $minuto); @endphp
            <option value="{{ $texto }}" @selected($minutoElegido === $texto)>{{ $texto }}</option>
        @endfor
    </select>
    <input type="hidden" name="{{ $name }}" value="{{ $valor }}" data-hora-valor>
</div>
