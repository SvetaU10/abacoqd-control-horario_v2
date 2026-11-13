@extends('layouts.app', ['titulo' => 'Comunicar incidencia'])

@section('contenido')
    <div class="comunicar">
        <header class="comunicar-cabecera">
            <a class="volver" href="{{ route('jornada') }}">Volver a hoy</a>
            <h1>Comunicar incidencia</h1>
        </header>

        @if ($nuevas->isNotEmpty())
            <section class="comunicar-panel comunicar-nuevas" aria-labelledby="titulo-nuevas-incidencias">
                <header class="comunicar-panel-cabeza">
                    <h2 id="titulo-nuevas-incidencias">Nuevas incidencias</h2>
                    <x-chip tipo="mensaje">{{ $nuevas->count() }}</x-chip>
                </header>
                <p class="comunicar-ayuda">Tienes incidencias sin abrir. Pulsa una para leerla.</p>
                <div class="incidencia-tabla">
                    <div class="incidencia-tabla-cabeza">
                        <span>Persona</span>
                        <span>Día afectado</span>
                        <span>Comunicación</span>
                        <span>Recibida</span>
                    </div>
                    @foreach ($nuevas as $incidencia)
                        <a class="incidencia-tabla-fila estado-{{ $incidencia->estado }}" href="{{ route('jornada.incidencia.crear', ['incidencia' => $incidencia->id]) }}">
                            <span class="incidencia-tabla-persona">
                                <span class="incidencia-inicial" aria-hidden="true">{{ mb_strtoupper(mb_substr($incidencia->autor->name, 0, 1)) }}</span>
                                {{ $incidencia->autor->name }}
                            </span>
                            <span>{{ $incidencia->created_at->locale('es')->isoFormat('D MMM YYYY') }}</span>
                            <span class="incidencia-tabla-comunicacion">{{ \Illuminate\Support\Str::limit($incidencia->cuerpo, 140) }}</span>
                            <span>{{ $incidencia->created_at->locale('es')->isoFormat('D MMM').' · '.$incidencia->created_at->format('H:i') }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="comunicar-panel">
            <header class="comunicar-panel-cabeza">
                <h2>Abrir incidencia</h2>
            </header>
            <p class="comunicar-ayuda">Cuenta qué ha pasado. El responsable lo verá en Incidencias.</p>
            <form method="post" action="{{ route('jornada.incidencia') }}" class="formulario comunicar-formulario">
                @csrf
                <label for="incidencia-motivo">Qué pasó</label>
                <textarea id="incidencia-motivo" name="motivo" required minlength="{{ \App\Models\Incidencia::CUERPO_MINIMO }}" maxlength="{{ \App\Models\Incidencia::CUERPO_MAXIMO }}" placeholder="{{ \App\Models\Incidencia::CUERPO_MINIMO }} a {{ \App\Models\Incidencia::CUERPO_MAXIMO }} caracteres" data-min="{{ \App\Models\Incidencia::CUERPO_MINIMO }}" data-max="{{ \App\Models\Incidencia::CUERPO_MAXIMO }}" data-vacio="Escribe el motivo de la incidencia." data-corto="El motivo tiene que tener al menos {{ \App\Models\Incidencia::CUERPO_MINIMO }} caracteres." data-largo="El motivo puede tener hasta {{ \App\Models\Incidencia::CUERPO_MAXIMO }} caracteres.">{{ old('motivo') }}</textarea>
                <button class="boton boton-principal" type="submit">Enviar Incidencia</button>
                <button class="historial-abre" type="button" data-historial-abre>Historial de incidencias</button>
            </form>
        </section>
    </div>

    <dialog class="historial-ventana" data-historial @if ($seleccion || request()->has('historial')) data-abierto @endif>
        <div class="historial-ventana-caja">
            <header class="comunicar-panel-cabeza">
                <h2>{{ $seleccion ? 'Chat' : 'Historial de incidencias' }}</h2>
                @if ($seleccion)
                    <a class="comunicar-nueva" href="{{ route('jornada.incidencia.crear', ['historial' => 1]) }}">Volver al historial</a>
                @endif
                <button class="historial-cerrar" type="button" data-historial-cerrar aria-label="Cerrar">✕</button>
            </header>

            @if ($seleccion)
                @include('incidencias.chat', ['incidencia' => $seleccion, 'sinCabecera' => true])
            @elseif ($historial->isEmpty())
                <p class="chat-vacio">Todavía no has enviado ninguna.</p>
            @else
                <div class="incidencia-tabla">
                    <div class="incidencia-tabla-cabeza">
                        <span>Persona</span>
                        <span>Día afectado</span>
                        <span>Comunicación</span>
                        <span>Recibida</span>
                    </div>
                    @foreach ($historial as $incidencia)
                        @php
                            $personaIncidencia = $incidencia->user_id === auth()->id() && $incidencia->destinatario
                                ? $incidencia->destinatario
                                : $incidencia->autor;
                        @endphp
                        <a class="incidencia-tabla-fila estado-{{ $incidencia->estado }}" href="{{ route('jornada.incidencia.crear', ['incidencia' => $incidencia->id]) }}">
                            <span class="incidencia-tabla-persona">
                                <span class="incidencia-inicial" aria-hidden="true">{{ mb_strtoupper(mb_substr($personaIncidencia->name, 0, 1)) }}</span>
                                {{ $personaIncidencia->name }}
                                @if ($incidencia->user_id === auth()->id() && $incidencia->destinatario)
                                    <span class="sr">Para {{ $incidencia->destinatario->name }}</span>
                                @endif
                            </span>
                            <span>{{ $incidencia->created_at->locale('es')->isoFormat('D MMM YYYY') }}</span>
                            <span class="incidencia-tabla-comunicacion">{{ \Illuminate\Support\Str::limit($incidencia->cuerpo, 140) }}</span>
                            <span>{{ $incidencia->created_at->locale('es')->isoFormat('D MMM').' · '.$incidencia->created_at->format('H:i') }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </dialog>
@endsection
