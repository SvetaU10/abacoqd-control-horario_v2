@extends('layouts.app', ['acceso' => true, 'titulo' => 'Pruebas finalizadas'])

@section('contenido')
    <div class="marca-acceso">
        <img class="isotipo isotipo-acceso" src="{{ asset('marca/logos/abacoqd_isotipo.svg') }}" alt="">
        <h1>Pruebas finalizadas</h1>
    </div>
    <p>El periodo de acceso a esta instalación ha terminado.</p>
    <p class="secundario">Los datos de las pruebas se conservan. Consulta con la persona responsable si necesitas volver a entrar.</p>
@endsection
