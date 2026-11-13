@props([
    'tipo' => 'secundario',
    'href' => null,
    'tipoHtml' => 'button',
])

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => 'boton boton-'.$tipo]) }}>
        {{ $slot }}
    </a>
@else
    <button type="{{ $tipoHtml }}" {{ $attributes->merge(['class' => 'boton boton-'.$tipo]) }}>
        {{ $slot }}
    </button>
@endif
