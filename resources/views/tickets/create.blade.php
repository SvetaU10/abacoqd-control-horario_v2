@extends('layouts.app', ['titulo' => 'Abrir un ticket'])

@section('contenido')
    <p><a class="enlace" href="{{ route('tickets.index') }}">Volver a Ayuda</a></p>
    <h1>Abrir un ticket</h1>
    <p class="secundario">Cuenta qué ha pasado y dónde. El técnico lo recibe con tu nombre. No pongas datos de salud ni de clientes.</p>

    <form method="post" action="{{ route('tickets.store') }}" class="formulario formulario-ticket">
        @csrf
        <input type="hidden" name="desde" value="{{ $desde }}">

        <label for="asunto">Asunto</label>
        <input id="asunto" name="asunto" type="text" maxlength="120" value="{{ old('asunto') }}" required>

        <label for="descripcion">Qué ha pasado</label>
        <textarea id="descripcion" name="descripcion" required placeholder="0 a 500 caracteres" data-max="500" data-vacio="Cuenta qué ha pasado." data-largo="La descripción puede tener hasta 500 caracteres.">{{ old('descripcion') }}</textarea>

        @if ($desde)
            <p class="secundario">Página desde la que lo abres: {{ $desde }}</p>
        @endif

        <button class="boton-hoy" type="submit">Enviar al técnico</button>
    </form>
@endsection
