@extends('layouts.app', ['ancha' => true, 'titulo' => 'Registro'])

@section('contenido')
    <div class="registro" data-registro>
        <header class="registro-cabeza">
            <div>
                <p class="espacio-sobre">Registro del equipo</p>
                <h1>Registro de {{ $persona->name }}</h1>
                <p class="secundario">{{ ucfirst($mesTexto) }}</p>
            </div>
            <x-boton tipo="secundario" :href="route('jefe.csv', ['user' => $persona, 'mes' => $mes])" class="registro-csv">
                <x-icono nombre="download" />
                Descargar copia
            </x-boton>
        </header>

        <div class="registro-barra">
            <form method="get" action="{{ route('jefe.registro', $persona) }}" class="registro-filtros">
                <div>
                    <label for="mes">Periodo</label>
                    <select id="mes" name="mes">
                        @foreach ($meses as $valor => $texto)
                            <option value="{{ $valor }}" @selected($valor === $mes)>{{ $texto }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="boton boton-secundario" type="submit">Ver mes</button>
            </form>
            <p class="registro-rango">{{ $registros->count() }} {{ $registros->count() === 1 ? 'registro' : 'registros' }}</p>
        </div>

        <section class="registro-card" aria-label="Registro de {{ $persona->name }}">
            @if ($registros->isEmpty())
                <p class="registro-vacio">No hay jornadas en este mes.</p>
            @else
                <div class="registro-tabla" role="table">
                    <div class="registro-cabecera" role="row">
                        <span role="columnheader">Fecha</span>
                        <span role="columnheader">Tramos de trabajo</span>
                        <span role="columnheader">Jornada</span>
                        <span role="columnheader">Total</span>
                        <span role="columnheader">Estado</span>
                        <span role="columnheader"></span>
                    </div>
                    @foreach ($registros as $registro)
                        @if ($registro['jornada'])
                            @php($jornada = $registro['jornada'])
                            @php($fila = $registro['fila'])
                            <a class="registro-fila" id="dia-{{ $jornada->work_date->toDateString() }}" role="row" href="{{ route('jefe.dia', $jornada) }}">
                                <span role="cell">{{ $fila['fecha'] }}</span>
                                <span role="cell" class="registro-tramos">{{ $fila['tramos'] }}</span>
                                <span role="cell" class="registro-barras" aria-hidden="true">
                                    @foreach ($fila['barras'] as $barra)
                                        <i style="left: {{ $barra['left'] }}%; width: {{ $barra['width'] }}%;"></i>
                                    @endforeach
                                </span>
                                <span role="cell" class="registro-total">{{ $fila['total'] }}</span>
                                <span role="cell" class="registro-estados">
                                    <x-chip :tipo="$fila['clase']">{{ $fila['estado'] }}</x-chip>
                                    @if ($fila['incidencia'])
                                        <x-chip tipo="mensaje">Incidencia</x-chip>
                                    @endif
                                    @if ($jornada->tramoAbierto())
                                        <small class="registro-subestado">Pendiente de cerrar</small>
                                    @elseif ($jornada->closed_late)
                                        <small class="registro-subestado">Cerrada al día siguiente</small>
                                    @endif
                                    @if ($jornada->tramos->contains(fn ($tramo) => $tramo->correcciones->isNotEmpty()))
                                        <small class="registro-subestado">con cambios</small>
                                    @endif
                                </span>
                                <span role="cell" class="registro-flecha" aria-hidden="true">›</span>
                            </a>
                        @elseif ($registro['carga'])
                            @php($carga = $registro['carga'])
                            <div class="registro-fila" id="dia-{{ $carga->fecha->toDateString() }}" role="row">
                                <span role="cell">{{ ucfirst($carga->fecha->copy()->locale('es')->isoFormat('dddd, D MMM')) }}</span>
                                <span role="cell" class="registro-tramos">{{ $tabla->horarioCarga($carga) }} <small>(estimado)</small></span>
                                <span role="cell" class="registro-barras" aria-hidden="true"></span>
                                <span role="cell" class="registro-total">{{ \App\Support\Tiempo::corto($carga->minutos) }}</span>
                                <span role="cell" class="registro-estados"><x-chip tipo="mensaje">{{ $tabla->etiquetaCarga($carga) }}</x-chip><small>Sin fichaje real</small></span>
                                <span role="cell"></span>
                            </div>
                        @else
                            @php($fechaSinFichar = $registro['fecha']->locale('es')->isoFormat('dddd, D MMM'))
                            <div class="registro-fila" id="dia-{{ $registro['fecha']->toDateString() }}" role="row">
                                <span role="cell">{{ ucfirst($fechaSinFichar) }}</span>
                                <span role="cell" class="registro-tramos">Sin fichaje</span>
                                <span role="cell" class="registro-barras" aria-hidden="true"></span>
                                <span role="cell" class="registro-total">0 h 00</span>
                                <span role="cell" class="registro-estados">
                                    <x-chip tipo="pendiente">Sin fichar</x-chip>
                                </span>
                                <span role="cell"></span>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif
            <div class="registro-pie">
                <span>{{ $registros->count() }} {{ $registros->count() === 1 ? 'registro' : 'registros' }}</span>
                <span>Las correcciones conservan el historial</span>
            </div>
        </section>

        <div class="sr" aria-hidden="true">
            @foreach ($semanas as $semana)
                Semana del {{ $semana['lunes']->locale('es')->isoFormat('D [de] MMMM') }} al {{ $semana['domingo']->locale('es')->isoFormat('D [de] MMMM') }}
            @endforeach
        </div>

        <section class="resumen resumen-fila" aria-label="Totales del mes">
            @if (($resumen['carga_historica'] ?? 0) || ($resumen['carga_prevision'] ?? 0))
                <div><span>Total combinado</span><strong>{{ \App\Support\Tiempo::corto($resumen['total_combinado']) }}</strong></div>
                <div><span>Fichajes reales</span><strong>{{ \App\Support\Tiempo::corto($resumen['registrados']) }}</strong></div>
                <div><span>Carga administrativa</span><strong>{{ \App\Support\Tiempo::corto($resumen['carga_historica']) }}</strong></div>
                <div><span>Previsión administrativa</span><strong>{{ \App\Support\Tiempo::corto($resumen['carga_prevision']) }}</strong></div>
            @else
                <div><span>Registrado</span><strong>{{ \App\Support\Tiempo::corto($resumen['registrados']) }}</strong></div>
            @endif
            <div><span>Previsto hasta hoy</span><strong>{{ \App\Support\Tiempo::corto($resumen['previstos']) }}</strong></div>
            <div><span>Por encima del horario</span><strong>{{ \App\Support\Tiempo::corto($resumen['porEncima']) }}</strong></div>
        </section>
        <span class="sr" aria-hidden="true">{{ \App\Support\Tiempo::texto($resumen['registrados']) }}</span>
    </div>
@endsection
