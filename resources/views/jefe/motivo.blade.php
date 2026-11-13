@extends('layouts.app', ['titulo' => 'Motivo'])

@section('contenido')
    <p><a class="enlace" href="{{ route('jefe.persona', $persona) }}">Volver a {{ $persona->name }}</a></p>
    <h1>Registro de {{ $persona->name }}</h1>
    <p>Para ver estos registros hace falta un motivo. La consulta queda escrita, con tu nombre, la fecha y la hora.</p>
    <p class="secundario">Escribe solo lo necesario. No pongas datos de salud ni de clientes.</p>
    <form method="post" action="{{ route('jefe.motivo', $persona) }}" class="formulario">
        @csrf
        <label for="reason">Motivo</label>
        <textarea id="reason" name="reason" maxlength="500" required>{{ old('reason') }}</textarea>
        <button class="boton boton-principal" type="submit">Ver el registro</button>
    </form>
@endsection
