@extends('layouts.app', ['acceso' => true, 'titulo' => 'Recuperar contraseña'])

@section('contenido')
    <div class="marca-acceso">
        <img class="isotipo isotipo-acceso" src="{{ asset('marca/logos/abacoqd_isotipo.svg') }}" alt="">
        <h1>Control horario</h1>
    </div>
    <p class="secundario">Escribe el correo de tu cuenta para solicitar un enlace de recuperación.</p>

    <form method="post" action="{{ route('password.email') }}" class="formulario">
        @csrf
        <label for="email">Correo</label>
        <input id="email" name="email" type="email" autocomplete="username" value="{{ old('email') }}" required>

        <button class="boton boton-principal" type="submit">Enviar enlace</button>
    </form>

    <p><a class="enlace-clave" href="{{ route('login') }}">Volver a entrar</a></p>
@endsection
