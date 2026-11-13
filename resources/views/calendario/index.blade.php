@extends('layouts.app', ['ancha' => true, 'titulo' => 'Calendario'])

@section('contenido')
    <link rel="stylesheet" href="{{ asset('css/vacaciones.css') }}?v={{ filemtime(public_path('css/vacaciones.css')) }}">
    <div class="calendario-pagina">
        <div data-calendario>
            @include('calendario.contenido')
        </div>

        {{-- Aparece al pulsar «Pedir vacaciones» en el panel del año. --}}
        <div class="vacaciones-pedir" data-vacaciones-barra hidden>
            <div>
                <strong>Solicitar vacaciones</strong>
                <p class="vacaciones-aviso" data-vacaciones-texto tabindex="-1" aria-live="polite">Elige un rango de fechas</p>
            </div>
            <div class="vacaciones-acciones">
                <button class="vacaciones-tick" type="button" data-vacaciones-aceptar aria-label="Enviar solicitud">✓</button>
                <button class="vacaciones-equis" type="button" data-vacaciones-cancelar aria-label="Cancelar">✕</button>
            </div>
            <form method="post" action="{{ route('calendario.vacaciones') }}" data-vacaciones-form hidden>
                @csrf
                <input type="hidden" name="desde" data-vacaciones-desde>
                <input type="hidden" name="hasta" data-vacaciones-hasta>
            </form>
        </div>
    </div>
@endsection
