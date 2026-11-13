@props(['ambito'])
@php
    $texto = match ($ambito) {
        'estado' => 'Nacional',
        'comunidad' => 'Autonómico',
        'pueblo' => 'Municipal',
        'provincia' => 'Provincial',
        'insular' => 'Insular',
        default => 'Festivo',
    };
@endphp
<span {{ $attributes->class(['festivo-ambito', 'festivo-ambito-'.$ambito])->merge(['title' => $texto]) }}>{{ $texto }}</span>
