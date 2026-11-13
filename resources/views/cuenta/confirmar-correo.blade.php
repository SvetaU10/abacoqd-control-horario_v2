@extends('layouts.app', ['ancha' => false, 'titulo' => 'Confirmar correo'])

@section('contenido')
    <h1>Confirmar correo</h1>
    <p>Confirma que quieres usar la dirección indicada en tu solicitud como correo de acceso.</p>
    <form method="post" action="{{ route('mi-cuenta.correo.aplicar') }}" id="confirmar-correo">
        @csrf
        <input type="hidden" name="token" id="confirmar-correo-token">
        <button class="boton boton-primario" type="submit" id="confirmar-correo-boton" disabled>Confirmar cambio</button>
    </form>
    <p id="confirmar-correo-invalido">Abre el enlace completo del correo en el navegador donde tienes tu sesión iniciada.</p>
    <noscript>Para confirmar el enlace necesitas activar JavaScript.</noscript>
    <script>
        (function () {
            var token = window.location.hash.slice(1);
            if (/^[a-f0-9]{64}$/.test(token)) {
                document.getElementById('confirmar-correo-token').value = token;
                document.getElementById('confirmar-correo-boton').disabled = false;
                document.getElementById('confirmar-correo-invalido').hidden = true;
                history.replaceState(null, '', window.location.pathname + window.location.search);
            }
        })();
    </script>
@endsection
