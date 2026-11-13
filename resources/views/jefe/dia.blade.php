@extends('layouts.app', ['ancha' => true, 'titulo' => 'Día'])

@section('contenido')
    @php
        $trabajos = $jornada->tramos->where('tipo', 'trabajo')->values();
        $primero = $trabajos->first();
        $ultimo = $trabajos->filter(fn ($tramo) => $tramo->ended_at)->last();
        $estado = $jornada->tramoAbierto() ? 'Abierta' : ($jornada->closed_late ? 'Fichado fuera de plazo' : 'Jornada cerrada');
        $estadoClase = $jornada->tramoAbierto() ? 'abierta' : ($jornada->closed_late ? 'tardia' : 'cerrada');
        $horario = $persona->horarioEn($jornada->work_date);
    @endphp

    <div class="detalle">
        <header class="detalle-cabeza">
            <div>
                <p class="espacio-sobre">{{ $persona->name }} · Registro</p>
                <h1>{{ \App\Support\Tiempo::fecha($jornada->work_date) }}</h1>
            </div>
            <a class="detalle-volver" href="{{ route('jefe.registro', ['user' => $persona, 'mes' => $jornada->work_date->format('Y-m')]) }}">
                <x-icono nombre="arrow-left" />
                Volver al registro
            </a>
        </header>

        <div class="detalle-resumen">
            <article class="detalle-dato">
                <p class="detalle-dato-etiqueta">
                    <x-icono nombre="clock" />
                    Trabajo registrado
                </p>
                <p class="detalle-dato-valor">{{ $total }}</p>
                <p class="secundario">{{ $trabajos->count() === 1 ? 'Un tramo de trabajo' : $trabajos->count().' tramos de trabajo' }}</p>
            </article>
            <article class="detalle-dato">
                <p class="detalle-dato-etiqueta">
                    <x-icono nombre="play" />
                    Inicio
                </p>
                <p class="detalle-dato-valor">{{ $primero ? \App\Support\Tiempo::hora($primero->started_at) : '—' }}</p>
                <p class="secundario">Hora de entrada</p>
            </article>
            <article class="detalle-dato">
                <p class="detalle-dato-etiqueta">
                    <x-icono nombre="square" />
                    Salida
                </p>
                <p class="detalle-dato-valor">{{ $ultimo ? \App\Support\Tiempo::hora($ultimo->ended_at) : '—' }}</p>
                <p class="secundario">{{ $jornada->tramoAbierto() ? 'Jornada abierta' : ($jornada->closed_late ? 'Fichado fuera de plazo' : 'Jornada cerrada') }}</p>
            </article>
        </div>

        @if ($porEncima)
            <p class="secundario detalle-extra">Tiempo por encima del horario: {{ \App\Support\Tiempo::texto($porEncima) }}. No es una hora extra y no hace falta autorización.</p>
        @endif

        <div class="detalle-cuerpo">
            <section class="detalle-panel">
                <div class="detalle-panel-cabeza">
                    <h2>Tramos del día</h2>
                    <x-chip :tipo="$estadoClase">{{ $estado }}</x-chip>
                </div>
                <div class="detalle-previsto">
                    <span>Registrado / previsto</span>
                    @if ($horario)
                        <span>{{ str_replace(' y ', ' · ', $horario->texto()) }}</span>
                    @endif
                </div>
                <div class="detalle-eje" aria-hidden="true">
                    <div class="detalle-eje-pista">
                        @foreach ($trabajos as $tramo)
                            @php
                                $fin = $tramo->ended_at ?? now();
                                $inicioMin = max(0, $tramo->started_at->hour * 60 + $tramo->started_at->minute - 420);
                                $duracion = max(1, $tramo->started_at->diffInMinutes($fin));
                            @endphp
                            <i style="left: {{ min(100, $inicioMin / 840 * 100) }}%; width: {{ min(100, $duracion / 840 * 100) }}%;"></i>
                        @endforeach
                    </div>
                    <div class="detalle-eje-horas">
                        @foreach ([7, 9, 11, 13, 15, 17, 19, 21] as $hora)
                            <span>{{ sprintf('%02d:00', $hora) }}</span>
                        @endforeach
                    </div>
                </div>

                @forelse ($jornada->tramos as $tramo)
                    <article class="detalle-tramo">
                        <span class="detalle-tramo-icono" aria-hidden="true">
                            @if ($tramo->tipo === 'pausa')
                                <x-icono nombre="pause" />
                            @else
                                <x-icono nombre="play" />
                            @endif
                        </span>
                        <div>
                            <p class="detalle-tramo-titulo">{{ $tramo->tipo === 'pausa' ? 'Pausa' : ($horario && $horario->sinTarde() ? 'Trabajo' : 'Trabajo de mañana') }}</p>
                            <p class="secundario">
                                {{ \App\Support\Tiempo::corto((int) round($tramo->started_at->diffInMinutes($tramo->ended_at ?? now()))) }} min
                            </p>
                            @if ($tramo->situacion === 'incidencia' || $tramo->nota)
                                <p class="secundario">{{ $tramo->nota ?: 'Incidencia de conexión' }}</p>
                            @elseif ($tramo->anotado_at && $tramo->anotado_at->format('H:i') !== $tramo->started_at->format('H:i'))
                                <p class="secundario">Anotado a las {{ \App\Support\Tiempo::hora($tramo->anotado_at) }}</p>
                            @endif
                        </div>
                        <p class="detalle-tramo-horas">{{ \App\Support\Tiempo::hora($tramo->started_at) }}–{{ $tramo->ended_at ? \App\Support\Tiempo::hora($tramo->ended_at) : 'ahora' }}</p>
                    </article>
                @empty
                    <p class="secundario">Este día no tiene fichaje.</p>
                @endforelse

                <p class="detalle-nota">
                    <x-icono nombre="info" />
                    Si una hora cambia, se conserva la anterior, con quién la cambió y cuándo.
                </p>
            </section>

            <aside class="detalle-panel">
                <h2>Historial de cambios</h2>
                @php($cambios = $jornada->tramos->flatMap(fn ($tramo) => $tramo->correcciones->map(fn ($correccion) => ['titulo' => ($correccion->field === 'ended_at' ? 'Salida' : 'Inicio').': '.\App\Support\Tiempo::hora($correccion->new_value), 'texto' => 'Antes '.\App\Support\Tiempo::hora($correccion->previous_value).', ahora '.\App\Support\Tiempo::hora($correccion->new_value).'.', 'quien' => ($correccion->autor->name ?? 'Alguien').' · '.\App\Support\Tiempo::fecha($correccion->created_at).' '.$correccion->created_at->format('H:i')]))->values())
                @forelse ($cambios as $cambio)
                    <ul class="detalle-cambios">
                        <li>
                            <p class="detalle-cambio-titulo">{{ $cambio['titulo'] }}</p>
                            <p>{{ $cambio['texto'] }}</p>
                            <p class="secundario">{{ $cambio['quien'] }}</p>
                        </li>
                    </ul>
                @empty
                    <p class="secundario">Todavía no hay cambios.</p>
                @endforelse
            </aside>
        </div>
    </div>
@endsection
