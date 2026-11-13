@extends('layouts.app', ['titulo' => 'Incidencias'])

@section('contenido')
    <div class="comunicar">
        <header class="comunicar-cabecera">
            <a class="volver" href="{{ route('jornada') }}">Volver a hoy</a>
            <h1>Incidencias</h1>
        </header>

        @php
            $filtro = array_filter([
                'persona' => $personaId,
                'desde' => $desde !== '' ? $desde : null,
                'hasta' => $hasta !== '' ? $hasta : null,
            ]);
        @endphp
        <div class="registro-tabs incidencias-tabs">
            <a href="{{ route('incidencias.index', $filtro) }}" @unless ($archivo) aria-current="page" @endunless>Activas</a>
            <a href="{{ route('incidencias.index', array_merge($filtro, ['archivo' => 1, 'historial' => 1])) }}" @if ($archivo) aria-current="page" @endif>Archivo</a>
        </div>

        @if ($nuevas->isNotEmpty())
            <section class="comunicar-panel comunicar-nuevas" aria-labelledby="titulo-nuevas-incidencias">
                <header class="comunicar-panel-cabeza">
                    <h2 id="titulo-nuevas-incidencias">Nuevas incidencias</h2>
                    <x-chip tipo="mensaje">{{ $nuevas->count() }}</x-chip>
                </header>
                <p class="comunicar-ayuda">Incidencias nuevas del equipo. Pulsa una para leerla.</p>
                <div class="incidencia-tabla">
                    <div class="incidencia-tabla-cabeza">
                        <span>Persona</span>
                        <span>Día afectado</span>
                        <span>Comunicación</span>
                        <span>Recibida</span>
                    </div>
                    @foreach ($nuevas as $incidencia)
                        <a class="incidencia-tabla-fila estado-{{ $incidencia->estado }}" href="{{ route('incidencias.show', $incidencia) }}">
                            <span class="incidencia-tabla-persona">
                                <span class="incidencia-inicial" aria-hidden="true">{{ mb_strtoupper(mb_substr($incidencia->autor->name, 0, 1)) }}</span>
                                {{ $incidencia->autor->name }}
                                @if ($incidencia->user_id === auth()->id() && $incidencia->destinatario)
                                    <span class="sr">Para {{ $incidencia->destinatario->name }}</span>
                                @endif
                            </span>
                            <span>{{ $incidencia->created_at->locale('es')->isoFormat('D MMM YYYY') }}</span>
                            <span class="incidencia-tabla-comunicacion">{{ \Illuminate\Support\Str::limit($incidencia->cuerpo, 140) }}</span>
                            <span>{{ $incidencia->created_at->locale('es')->isoFormat('D MMM').' · '.$incidencia->created_at->format('H:i') }}</span>
                            <span class="sr">{{ $incidencia->estadoTexto() }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="comunicar-panel">
            <header class="comunicar-panel-cabeza">
                <h2>Comunicar incidencia</h2>
            </header>
            <p class="comunicar-ayuda">Cuenta qué ha pasado. Puedes comunicárselo a una persona del equipo.</p>
            <form method="post" action="{{ route('jornada.incidencia') }}" class="formulario comunicar-formulario">
                @csrf
                <label for="incidencia-destinatario">Para</label>
                <select id="incidencia-destinatario" name="destinatario" required>
                    <option value="">Elige una persona</option>
                    @foreach ($equipo as $persona)
                        <option value="{{ $persona->id }}" @selected((string) old('destinatario') === (string) $persona->id)>{{ $persona->name }}</option>
                    @endforeach
                </select>
                <label for="incidencia-motivo">Qué pasó</label>
                <textarea id="incidencia-motivo" name="motivo" required minlength="{{ \App\Models\Incidencia::CUERPO_MINIMO }}" maxlength="{{ \App\Models\Incidencia::CUERPO_MAXIMO }}" placeholder="{{ \App\Models\Incidencia::CUERPO_MINIMO }} a {{ \App\Models\Incidencia::CUERPO_MAXIMO }} caracteres" data-min="{{ \App\Models\Incidencia::CUERPO_MINIMO }}" data-max="{{ \App\Models\Incidencia::CUERPO_MAXIMO }}" data-vacio="Escribe el motivo de la incidencia." data-corto="El motivo tiene que tener al menos {{ \App\Models\Incidencia::CUERPO_MINIMO }} caracteres." data-largo="El motivo puede tener hasta {{ \App\Models\Incidencia::CUERPO_MAXIMO }} caracteres.">{{ old('motivo') }}</textarea>
                <button class="boton boton-principal" type="submit">Enviar incidencia</button>
            </form>
        </section>
    </div>

    <dialog class="historial-ventana" data-historial @if ($archivo || request()->has('historial')) data-abierto @endif>
        <div class="historial-ventana-caja">
            <header class="comunicar-panel-cabeza">
                <h2>Historial de incidencias</h2>
                <button class="historial-cerrar" type="button" data-historial-cerrar aria-label="Cerrar">✕</button>
            </header>
            <form method="get" class="filtros-incidencia" action="{{ route('incidencias.index') }}">
                <input type="hidden" name="historial" value="1">
                @if ($archivo)
                    <input type="hidden" name="archivo" value="1">
                @endif
                <div class="campo-incidencia">
                    <label for="persona">Persona</label>
                    <select id="persona" name="persona">
                        <option value="">Todas</option>
                        @foreach ($personas as $persona)
                            <option value="{{ $persona->id }}" @selected($personaId === $persona->id)>{{ $persona->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="campo-incidencia">
                    <label for="desde">Desde</label>
                    <x-fecha id="desde" name="desde" :value="$desde" />
                </div>
                <div class="campo-incidencia">
                    <label for="hasta">Hasta</label>
                    <x-fecha id="hasta" name="hasta" :value="$hasta" />
                </div>
                <button class="boton boton-secundario" type="submit">Ver</button>
            </form>
            <a class="boton-hoy @if ($desde === $hoy && $hasta === $hoy && $personaId === null) boton-hoy-activo @endif" href="{{ route('incidencias.index', ['desde' => $hoy, 'hasta' => $hoy, 'historial' => 1, 'archivo' => $archivo ? 1 : null]) }}">Hoy</a>
            @if ($incidencias->isEmpty())
                <p class="incidencia-vacia">{{ $archivo ? 'No hay incidencias en el archivo.' : 'No hay incidencias activas.' }}</p>
            @else
                <div class="incidencia-tabla">
                    <div class="incidencia-tabla-cabeza">
                        <span>Persona</span>
                        <span>Día afectado</span>
                        <span>Comunicación</span>
                        <span>Recibida</span>
                    </div>
                    @foreach ($incidencias as $incidencia)
                        <a class="incidencia-tabla-fila estado-{{ $incidencia->estado }}" href="{{ route('incidencias.show', $incidencia) }}">
                            <span class="incidencia-tabla-persona">
                                <span class="incidencia-inicial" aria-hidden="true">{{ mb_strtoupper(mb_substr($incidencia->autor->name, 0, 1)) }}</span>
                                {{ $incidencia->autor->name }}
                                @if ($incidencia->user_id === auth()->id() && $incidencia->destinatario)
                                    <span class="sr">Para {{ $incidencia->destinatario->name }}</span>
                                @endif
                            </span>
                            <span>{{ $incidencia->created_at->locale('es')->isoFormat('D MMM YYYY') }}</span>
                            <span class="incidencia-tabla-comunicacion">{{ \Illuminate\Support\Str::limit($incidencia->cuerpo, 110) }}</span>
                            <span>{{ $incidencia->created_at->locale('es')->isoFormat('D MMM').' · '.$incidencia->created_at->format('H:i') }}</span>
                            <span class="sr">{{ $incidencia->estadoTexto() }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </dialog>
@endsection
