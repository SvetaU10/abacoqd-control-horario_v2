@extends('layouts.app', ['ancha' => true, 'titulo' => 'Motivo'])

@section('contenido')
    <h1>Registro de {{ $persona->name }}</h1>
    <p>Para ver estos registros hace falta un motivo. La consulta queda escrita.</p>
    <form method="post" action="{{ route('equipo.motivo', $persona) }}" class="formulario">
        @csrf
        <label for="reason">Motivo</label>
        <textarea id="reason" name="reason" maxlength="500" required>{{ old('reason') }}</textarea>
        <button class="boton boton-principal" type="submit">Ver el registro</button>
    </form>
@endsection
