@extends('layouts.app', ['ancha' => true, 'titulo' => $detalle['titulo'], 'miga' => $crumb])

@section('contenido')
    <div class="detalle">
        <header class="detalle-cabeza">
            <div>
                <p class="espacio-sobre">{{ $crumb['texto'] }}</p>
                <h1>{{ $detalle['titulo'] }}</h1>
                <p class="secundario">{{ $persona->name }} · Detalle del registro</p>
            </div>
            <a class="detalle-volver" href="{{ $crumb['url'] }}">
                <x-icono nombre="arrow-left" />
                Volver a {{ $crumb['texto'] }}
            </a>
        </header>

        <div class="detalle-resumen">
            <article class="detalle-dato">
                <p class="detalle-dato-etiqueta">
                    <x-icono nombre="clock" />
                    Trabajo registrado
                </p>
                <p class="detalle-dato-valor">{{ $detalle['total'] }}</p>
                <p class="secundario">{{ $detalle['tramosTexto'] }}</p>
            </article>
            <article class="detalle-dato">
                <p class="detalle-dato-etiqueta">
                    <x-icono nombre="play" />
                    Inicio
                </p>
                <p class="detalle-dato-valor">{{ $detalle['inicio'] }}</p>
                <p class="secundario">Hora de entrada</p>
            </article>
            <article class="detalle-dato">
                <p class="detalle-dato-etiqueta">
                    <x-icono nombre="square" />
                    Salida
                </p>
                <p class="detalle-dato-valor">{{ $detalle['salida'] }}</p>
                <p class="secundario">{{ $detalle['salidaTexto'] }}</p>
            </article>
        </div>
        @if ($detalle['porEncima'])
            <p class="secundario detalle-extra">Tiempo por encima del horario: {{ \App\Support\Tiempo::texto($detalle['porEncima']) }}. No es una hora extra y no hace falta autorización.</p>
        @endif

        <div class="detalle-cuerpo">
            <section class="detalle-panel">
                <div class="detalle-panel-cabeza">
                    <h2>Tramos del día</h2>
                    <x-chip :tipo="$detalle['estadoClase']">{{ $detalle['estado'] }}</x-chip>
                </div>
                <div class="detalle-previsto">
                    <span>Registrado / previsto</span>
                    @if ($detalle['previsto'])
                        <span>{{ $detalle['previsto'] }}</span>
                    @endif
                </div>
                <div class="detalle-eje" aria-hidden="true">
                    <div class="detalle-eje-pista">
                        @foreach ($detalle['barras'] as $barra)
                            <i style="left: {{ $barra['left'] }}%; width: {{ $barra['width'] }}%;"></i>
                        @endforeach
                    </div>
                    <div class="detalle-eje-horas">
                        @foreach ([7, 9, 11, 13, 15, 17, 19, 21] as $hora)
                            <span>{{ sprintf('%02d:00', $hora) }}</span>
                        @endforeach
                    </div>
                </div>

                @forelse (collect($detalle['filas'])->where('visible', true) as $fila)
                    <article class="detalle-tramo">
                        <span class="detalle-tramo-icono" aria-hidden="true">
                            @if ($fila['tramo']->tipo === 'pausa')
                                <x-icono nombre="pause" />
                            @else
                                <x-icono nombre="play" />
                            @endif
                        </span>
                        <div>
                            <p class="detalle-tramo-titulo">{{ $fila['titulo'] }}</p>
                        </div>
                        <p class="detalle-tramo-horas">{{ $fila['horas'] }}</p>
                        @if ($puedeCorregir)
                            <button class="detalle-corregir" type="button" data-dialogo-abre="corregir-{{ $fila['tramo']->id }}">
                                <x-icono nombre="clock" />
                                Corregir
                            </button>
                        @endif
                    </article>
                @empty
                    <p class="secundario">{{ $explicar ? 'Este día no tiene fichaje.' : 'Este día no tiene tramos.' }}</p>
                @endforelse

                @if ($puedeRegistrar)
                    <div class="detalle-registrar">
                        <button class="detalle-registrar-boton" type="button" data-dialogo-abre="registrar-horas">Registrar las horas</button>
                    </div>
                @endif

                @if ($puedeExplicar ?? false)
                    <div class="detalle-registrar">
                        <button class="detalle-registrar-boton" type="button" data-dialogo-abre="explicar-dia">Explicar este día</button>
                    </div>
                @endif

                @if (! $puedeCorregir && $detalle['filas'] !== [])
                    <p class="secundario">Para cambiar este día, habla con tu responsable.</p>
                @endif
                <p class="detalle-nota">
                    <x-icono nombre="info" />
                    Si una hora cambia, se conserva la anterior, con quién la cambió y cuándo.
                </p>
            </section>

            <aside class="detalle-panel">
                <h2>Historial de cambios</h2>
                @if ($detalle['cambios'] === [])
                    <p class="secundario">Todavía no hay cambios.</p>
                @else
                    <ul class="detalle-cambios">
                        @foreach ($detalle['cambios'] as $cambio)
                            <li>
                                <p class="detalle-cambio-titulo">{{ $cambio['titulo'] }}</p>
                                @if ($cambio['texto'] !== '')
                                    <p>{{ $cambio['texto'] }}</p>
                                @endif
                                <p class="secundario">{{ $cambio['quien'] }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </aside>
        </div>
    </div>

    @if ($puedeCorregir)
        @foreach (collect($detalle['filas'])->where('visible', true) as $fila)
            <dialog class="dialogo-hoy" data-dialogo="corregir-{{ $fila['tramo']->id }}" @if (($abrirCorregir ?? false) && $loop->first) data-abierto @endif aria-labelledby="titulo-corregir-{{ $fila['tramo']->id }}">
                <div class="dialogo-cabecera">
                    <h2 id="titulo-corregir-{{ $fila['tramo']->id }}">Corregir horas</h2>
                    <form method="dialog">
                        <button class="dialogo-cerrar" type="submit">Cerrar</button>
                    </form>
                </div>
                <form method="post" action="{{ route('tramos.corregir', $fila['tramo']) }}" class="formulario">
                    @csrf
                    <div class="horas-par">
                        <div data-correccion-hora="inicio">
                            <label for="inicio-{{ $fila['tramo']->id }}">
                                <span data-etiqueta-normal>Hora de inicio</span>
                                <span data-etiqueta-pausa hidden>Inicio de la pausa</span>
                            </label>
                            <x-hora id="inicio-{{ $fila['tramo']->id }}" name="inicio" :valor="\App\Support\Tiempo::hora($fila['tramo']->started_at)" :required="false" />
                        </div>
                        <div data-correccion-hora="fin">
                            <label for="fin-{{ $fila['tramo']->id }}">
                                <span data-etiqueta-normal>Hora de fin</span>
                                <span data-etiqueta-pausa hidden>Fin de la pausa</span>
                            </label>
                            <x-hora id="fin-{{ $fila['tramo']->id }}" name="fin" :valor="$fila['tramo']->ended_at ? \App\Support\Tiempo::hora($fila['tramo']->ended_at) : ''" :required="false" />
                        </div>
                    </div>
                    <label for="motivo-{{ $fila['tramo']->id }}">Motivo</label>
                    <select id="motivo-{{ $fila['tramo']->id }}" name="motivo" required data-motivo-correccion>
                        @foreach ($motivos as $clave => $texto)
                            @if ($clave === 'hora' || ($clave === 'fichar_pausa' && ($fila['puedeFicharPausa'] ?? false)))
                                <option value="{{ $clave }}">{{ $texto }}</option>
                            @endif
                        @endforeach
                    </select>
                    <label for="nota-{{ $fila['tramo']->id }}">Nota, si hace falta</label>
                    <textarea id="nota-{{ $fila['tramo']->id }}" name="nota" maxlength="500"></textarea>
                    <p class="secundario">Puedes guardar solo el inicio, solo el fin, o las dos. No pongas datos de salud ni de clientes.</p>
                    <button class="boton boton-secundario" type="submit">Guardar</button>
                </form>
            </dialog>
        @endforeach
    @endif

    @if ($puedeRegistrar)
        <dialog class="dialogo-hoy" data-dialogo="registrar-horas" @if ($abrirRegistrar ?? false) data-abierto @endif aria-labelledby="titulo-registrar-horas">
            <div class="dialogo-cabecera">
                <h2 id="titulo-registrar-horas">Registrar las horas</h2>
                <form method="dialog">
                    <button class="dialogo-cerrar" type="submit">Cerrar</button>
                </form>
            </div>
            <form method="post" action="{{ route('registro.dia.horas', $detalle['fecha']) }}" class="formulario">
                @csrf
                <p class="secundario">Elige la hora de entrada y de salida de este día.</p>
                <div class="horas-par">
                    <div>
                        <label for="registrar-inicio">Hora de inicio</label>
                        <x-hora id="registrar-inicio" name="inicio" />
                    </div>
                    <div>
                        <label for="registrar-fin">Hora de fin</label>
                        <x-hora id="registrar-fin" name="fin" />
                    </div>
                </div>
                <button class="boton boton-principal" type="submit">Registrar las horas</button>
            </form>
        </dialog>
    @endif

    @if ($puedeExplicar ?? false)
        <dialog class="dialogo-hoy" data-dialogo="explicar-dia" @if ($abrirExplicar ?? false) data-abierto @endif aria-labelledby="titulo-explicar-dia">
            <div class="dialogo-cabecera">
                <h2 id="titulo-explicar-dia">Explica este día</h2>
                <form method="dialog">
                    <button class="dialogo-cerrar" type="submit">Cerrar</button>
                </form>
            </div>
            <form method="post" action="{{ route('registro.explicar') }}" class="formulario">
                @csrf
                <input type="hidden" name="work_date" value="{{ $detalle['fecha'] }}">
                <p class="secundario">Este día ya no se puede corregir. La explicación queda en tu registro.</p>
                <label for="explicar-cuerpo">Explicación</label>
                <textarea id="explicar-cuerpo" name="body" maxlength="500" required></textarea>
                <p class="secundario">No pongas datos de salud ni de clientes.</p>
                <button class="boton boton-principal" type="submit">Guardar</button>
            </form>
        </dialog>
    @endif
@endsection
