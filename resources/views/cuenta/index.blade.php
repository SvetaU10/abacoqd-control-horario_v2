@extends('layouts.app', ['ancha' => false, 'titulo' => 'Mi cuenta'])

@section('contenido')
    <h1>Mi cuenta</h1>
    <p>Correo de acceso actual: <strong>{{ $persona->email }}</strong></p>
    @if (! $persona->correo_habilitado)
        <p>Tu dirección actual es provisional. Confirma una dirección real para recibir mensajes y recuperar tu contraseña.</p>
    @endif
    <h2>Cambiar correo de acceso</h2>
    <p class="secundario">Te enviaremos un enlace al correo nuevo. Tu dirección actual seguirá funcionando hasta que confirmes el cambio.</p>
    <form method="post" action="{{ route('mi-cuenta.correo.solicitar') }}" class="formulario">
        @csrf
        <label for="email">Correo nuevo</label>
        <input id="email" name="email" type="email" maxlength="190" value="{{ old('email') }}" autocomplete="email" required>
        @error('email') <p role="alert">{{ $message }}</p> @enderror
        <label for="current_password">Contraseña actual</label>
        <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
        @error('current_password') <p role="alert">{{ $message }}</p> @enderror
        <button class="boton boton-primario" type="submit">Enviar enlace de confirmación</button>
    </form>
@endsection
