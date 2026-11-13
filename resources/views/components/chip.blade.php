@props(['tipo' => 'fuera'])

<span {{ $attributes->merge(['class' => 'chip chip-'.$tipo]) }}>
    @php
        $icono = match ($tipo) {
            'en-curso', 'abierta', 'en_curso' => 'play',
            'en-pausa' => 'pause',
            'pendiente', 'tardia', 'abierto' => 'clock',
            'cerrada', 'resuelta', 'resuelto' => 'check',
            'incidencia' => 'alert',
            'mensaje' => 'message',
            default => 'circle',
        };
    @endphp
    <x-icono :nombre="$icono" />
    {{ $slot }}
</span>
