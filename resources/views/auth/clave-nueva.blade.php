@extends('layouts.app', ['acceso' => true, 'titulo' => 'Contraseña nueva'])

@section('contenido')
    <div class="marca-acceso">
        <img class="isotipo isotipo-acceso" src="{{ asset('marca/logos/abacoqd_isotipo.svg') }}" alt="">
        <h1>Control horario</h1>
    </div>
    <p class="secundario">Elige una contraseña nueva para tu acceso.</p>

    <form method="post" action="{{ route('password.update') }}" class="formulario">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <label for="email">Correo</label>
        <input id="email" name="email" type="email" autocomplete="username" value="{{ old('email', $email) }}" required>

        <label for="password">Contraseña nueva</label>
        <div class="campo-clave">
            <input id="password" name="password" type="password" autocomplete="new-password" required minlength="8">
            <button type="button" class="ver-clave" data-ver-clave aria-controls="password" aria-pressed="false" aria-label="Ver contraseña">
                <x-icono nombre="eye" class="ojo ojo-abierto" hidden />
                <svg class="ojo ojo-cerrado" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M3 3l18 18"/>
                    <path d="M10.6 6.1A10.7 10.7 0 0 1 12 6c6.5 0 10 6 10 6a18.5 18.5 0 0 1-4.1 4.6"/>
                    <path d="M6.7 6.7C3.6 8.6 2 12 2 12s3.5 7 10 7a9.8 9.8 0 0 0 4.2-.9"/>
                    <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>
                </svg>
            </button>
        </div>

        <label for="password_confirmation">Repite la contraseña</label>
        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required minlength="8">

        <button class="boton boton-principal" type="submit">Guardar contraseña</button>
    </form>

    <p><a class="enlace-clave" href="{{ route('login') }}">Volver a entrar</a></p>
    <script>
        (function () {
            var boton = document.querySelector('[data-ver-clave]');
            var campo = document.getElementById('password');
            if (!boton || !campo) {
                return;
            }
            boton.addEventListener('click', function () {
                var mostrar = campo.type === 'password';
                campo.type = mostrar ? 'text' : 'password';
                boton.querySelector('.ojo-abierto').toggleAttribute('hidden', !mostrar);
                boton.querySelector('.ojo-cerrado').toggleAttribute('hidden', mostrar);
                boton.setAttribute('aria-pressed', mostrar ? 'true' : 'false');
                boton.setAttribute('aria-label', mostrar ? 'Ocultar contraseña' : 'Ver contraseña');
            });
        })();
    </script>
@endsection
