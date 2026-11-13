@extends('layouts.app', ['compacta' => true, 'titulo' => 'Hoy'])

@section('contenido')
    <span data-clock data-server-ms="{{ $serverMs }}" hidden></span>
    <header class="espacio-saludo">
        <div>
            <p class="espacio-sobre">Tu espacio de trabajo</p>
            <h1>{{ $saludo }}, {{ $nombre }}</h1>
            <p class="espacio-fecha">{{ $fechaLarga }}</p>
        </div>
        <x-chip :tipo="$pastilla['clase']">{{ $pastilla['texto'] }}</x-chip>
    </header>

    @if ($banner)
        @if (($banner['accion'] ?? null) === 'completar')
            <form method="post" action="{{ route('jornada.completar') }}">
                @csrf
                <x-franja>
                    {{ $banner['texto'] }}
                    <x-slot:primero>
                        <x-campo-hora id="hora-cierre" name="hora" :min="$banner['min']" :max="$banner['max']" />
                    </x-slot:primero>
                    <x-slot:segundo>
                        <button class="boton boton-secundario" type="submit">Completar</button>
                    </x-slot:segundo>
                </x-franja>
            </form>
        @elseif (($banner['accion'] ?? null) === 'registro')
            <x-franja>
                {{ $banner['texto'] }}
                <x-slot:primero>
                    <a class="franja-accion" href="{{ $banner['jornada'] ? route('registro.show', $banner['jornada']).'?abrir=corregir' : route('registro.dia', $banner['fecha']).'?abrir=registrar-horas' }}">Revisar</a>
                </x-slot:primero>
            </x-franja>
        @elseif (($banner['accion'] ?? null) === 'calendario')
            <x-franja class="franja-info">
                {{ $banner['texto'] }}
                <x-slot:primero>
                    @if ($esJefa && auth()->user()->centro_id)
                        <a class="franja-accion" href="{{ route('gestion.calendario.mostrar', auth()->user()->centro_id) }}">Cargar calendario</a>
                    @else
                        <a class="franja-accion" href="{{ route('calendario') }}">Ver calendario</a>
                    @endif
                </x-slot:primero>
            </x-franja>
        @elseif (($banner['accion'] ?? null) === 'cierre')
            <x-franja>
                {{ $banner['texto'] }}
                <x-slot:primero>
                    <form method="post" action="{{ route('jornada.cerrar') }}">
                        @csrf
                        <input type="hidden" name="hora" value="{{ $banner['hora'] }}">
                        <button class="franja-accion" type="submit">Terminé a las {{ $banner['hora'] }}</button>
                    </form>
                </x-slot:primero>
                <x-slot:segundo>
                    <form method="post" action="{{ route('jornada.seguir') }}">
                        @csrf
                        <button class="franja-accion" type="submit">Sigo</button>
                    </form>
                </x-slot:segundo>
            </x-franja>
        @elseif (($banner['accion'] ?? null) === 'festivo')
            <x-franja>
                {{ $banner['texto'] }}
                <x-slot:primero>
                    <form method="post" action="{{ route('jornada.festivo') }}">
                        @csrf
                        <button class="franja-accion" name="respuesta" value="si" type="submit">Sí, hay trabajo</button>
                    </form>
                </x-slot:primero>
                <x-slot:segundo>
                    <form method="post" action="{{ route('jornada.festivo') }}">
                        @csrf
                        <button class="franja-accion" name="respuesta" value="no" type="submit">No</button>
                    </form>
                </x-slot:segundo>
            </x-franja>
        @elseif (($banner['accion'] ?? null) === 'inicio')
            <x-franja>
                {{ $banner['texto'] }}
                <x-slot:primero>
                    <form method="post" action="{{ route('jornada.reunion') }}">
                        @csrf
                        <button class="franja-accion" type="submit">Ya estaba trabajando</button>
                    </form>
                </x-slot:primero>
            </x-franja>
        @endif
    @endif

    @if ($expectativa['estado'] === 'desconocido' && ($banner['accion'] ?? null) !== 'calendario')
        <x-franja class="franja-info">
            {{ $expectativa['motivo'] === 'horario' ? 'Tu horario está pendiente de asignar.' : 'El calendario de tu centro para '.now()->year.' está pendiente de confirmar.' }}
            Puedes fichar el trabajo real. Los avisos de inicio están en pausa.
            @if ($expectativa['motivo'] === 'calendario')
                <x-slot:primero>
                    <a class="franja-accion" href="{{ route('calendario') }}">Ver calendario</a>
                </x-slot:primero>
            @endif
        </x-franja>
    @endif

    <div class="espacio">
        <div class="espacio-columna">
            <section class="tarjeta tarjeta-jornada" aria-live="polite">
                @if (session('ok'))
                    <p class="chip-guardado" role="status">{{ session('ok') }}</p>
                @endif
                @if (session('aviso'))
                    <p class="franja franja-dentro" role="status">{{ session('aviso') }}</p>
                @endif
                @if ($errors->any())
                    <p class="franja franja-dentro" role="alert">{{ $errors->first() }}</p>
                @endif

                <div class="tarjeta-cabeza">
                    <h2>Jornada de hoy</h2>
                    <p>{{ $centroNombre }}@if ($tipoLargo) · {{ $tipoLargo }}@endif</p>
                </div>

                <div class="jornada-estado">
                    @php
                        $iconoJornada = match ($estado) {
                            'fuera' => 'play',
                            'trabajando' => 'pause',
                            'pausa' => 'pause',
                            'cerrada' => 'check',
                            default => null,
                        };
                        $assetJornada = match ($estado) {
                            'trabajando' => 'trabajando.svg',
                            'pendiente' => 'pendiente.svg',
                            default => 'fuera_de_jornada.svg',
                        };
                    @endphp
                    <span class="jornada-marca marca-{{ $estado }}" aria-hidden="true">
                        @if ($iconoJornada)
                            <x-icono :nombre="$iconoJornada" />
                        @else
                            <img src="{{ asset('marca/iconos/'.$assetJornada) }}" alt="">
                        @endif
                    </span>
                    <div>
                        @if ($desdeTexto)
                            <p class="espacio-kicker">{{ $desdeTexto }}</p>
                        @endif
                        <p class="jornada-titulo">{{ $estadoTexto }}</p>
                    </div>
                </div>

                <div class="jornada-medida">
                    <p class="jornada-cifra">
                        <span class="jornada-cifra-valor">
                            <strong data-llevas data-corto="1" data-cerrados="{{ $minutosCerrados }}" @if ($abiertoMs) data-abierto-ms="{{ $abiertoMs }}" @endif>{{ $hoyLargo }}</strong>
                            <span class="jornada-unidad">min</span>
                        </span>
                        <span>Tiempo trabajado hoy</span>
                    </p>
                    <div class="jornada-acciones">
                        @if ($puedeVolver)
                            <form method="post" action="{{ route('jornada.volver') }}">
                                @csrf
                                <button class="boton-hoy boton-hoy-principal" type="submit">Volver</button>
                            </form>
                            <form method="post" action="{{ route('jornada.salida') }}">
                                @csrf
                                <button class="boton-hoy boton-hoy-linea" type="submit">Terminar jornada</button>
                            </form>
                        @elseif ($puedeSalir)
                            <form method="post" action="{{ route('jornada.salida') }}">
                                @csrf
                                <button class="boton-hoy boton-hoy-principal" type="submit">
                                    <x-icono nombre="square" />
                                    Terminar jornada
                                </button>
                            </form>
                            @if ($puedePausar)
                                <form method="post" action="{{ route('jornada.pausa') }}">
                                    @csrf
                                    <button class="boton-hoy boton-hoy-linea" type="submit">
                                        <x-icono nombre="pause" />
                                        Pausa
                                    </button>
                                </form>
                            @endif
                        @elseif ($puedeEntrar)
                            <div class="jornada-empezar">
                                <p class="etiqueta-estado">Inicio de jornada</p>
                                <form method="post" action="{{ route('jornada.entrada') }}" @if ($pideConfirmacionTemprana) data-confirma-temprano @endif>
                                    @csrf
                                    @if ($puedeEntrar && $inicioTarde)
                                        <button class="boton-hoy boton-hoy-principal" type="button" data-dialogo-abre="inicio-tarde">Empezar jornada</button>
                                    @else
                                        <button class="boton-hoy boton-hoy-principal" type="submit">Empezar jornada</button>
                                    @endif
                                </form>
                                @if ($pideConfirmacionTemprana && $horaInicioPrevista)
                                    <dialog
                                        class="dialogo"
                                        id="confirma-temprano"
                                        aria-labelledby="confirma-temprano-texto"
                                        @if (session('confirma_temprano')) data-abrir @endif
                                    >
                                        <p id="confirma-temprano-texto">Tu jornada empieza a las {{ $horaInicioPrevista }}. ¿Empiezas ya?</p>
                                        <div class="dialogo-acciones">
                                            <form method="post" action="{{ route('jornada.entrada') }}">
                                                @csrf
                                                <input type="hidden" name="confirmar_temprano" value="1">
                                                <button class="boton boton-principal" type="submit">Sí, empiezo</button>
                                            </form>
                                            <button class="boton boton-secundario" type="button" data-cierra-dialogo>Todavía no</button>
                                        </div>
                                    </dialog>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>

                <div class="eje-leyenda">
                    <span>Registrado / previsto</span>
                    @if ($previstoTexto)
                        <span>{{ $previstoTexto }}</span>
                    @endif
                </div>
                <div class="eje" aria-hidden="true">
                    @foreach ($barra['previsto'] as $trozo)
                        <span class="eje-previsto" style="left: {{ $trozo['left'] }}%; width: {{ $trozo['width'] }}%;"></span>
                    @endforeach
                    @foreach ($barra['hecho'] as $trozo)
                        <span class="eje-hecho" style="left: {{ $trozo['left'] }}%; width: {{ $trozo['width'] }}%;" @if ($trozo['abierto']) data-eje-vivo data-eje-left="{{ $trozo['left'] }}" @endif></span>
                    @endforeach
                </div>
                <div class="eje-marcas" aria-hidden="true">
                    @foreach (['07:00', '09:00', '11:00', '13:00', '15:00', '17:00', '19:00', '21:00'] as $marca)
                        <span>{{ $marca }}</span>
                    @endforeach
                </div>

                @if ($salidaTexto || $porEncimaTexto || $notaDia)
                    <p class="secundario jornada-nota">{{ $salidaTexto }} {{ $porEncimaTexto }} {{ $notaDia }}</p>
                @endif

                @if ($puedeEntrar)
                    @unless ($esJefa)
                        <p class="secundario aviso-conexion" data-conexion-vuelta hidden>Ya hay conexión. Entra y declara las horas que no se guardaron.</p>
                    @endunless
                @elseif (($banner['accion'] ?? null) === 'cierre')
                    <div class="acciones-secundarias">
                        <button class="accion-secundaria" type="button" data-dialogo-abre="otra-hora">Terminé a otra hora</button>
                    </div>
                @elseif (($banner['accion'] ?? null) === 'completar')
                    <div class="acciones-secundarias">
                        <a class="accion-secundaria" href="{{ route('registro', ['estado' => 'pendiente']) }}">Ver en Mi registro</a>
                    </div>
                @endif

                @if ($puedeBorrarPrueba ?? false)
                    <form method="post" action="{{ route('jornada.borrar-prueba') }}">
                        @csrf
                        <button class="enlace-prueba" type="submit">Borrar fichaje de hoy</button>
                    </form>
                    <form method="post" action="{{ route('jornada.borrar-ayer') }}">
                        @csrf
                        <button class="enlace-prueba" type="submit">Borrar fichaje de ayer</button>
                    </form>
                    <form method="post" action="{{ route('jornada.borrar-tres-dias') }}">
                        @csrf
                        <button class="enlace-prueba" type="submit">Borrar fichaje de hace 3 días</button>
                    </form>
                @endif
            </section>
        </div>

        <div class="espacio-columna">
            <section class="tarjeta">
                <div class="tarjeta-cabeza">
                    <h2>Esta semana</h2>
                    <a class="tarjeta-enlace" href="{{ route('registro') }}">Ver registro</a>
                </div>
                <p class="semana-cifra" data-semana data-cerrados="{{ $semanaCerrados }}">{{ $semanaTexto }}</p>
                @if ($semanaTieneCarga)
                    <p class="espacio-kicker">Total combinado · {{ $semanaRango }}</p>
                    <p class="secundario semana-nota">
                        Fichajes reales: {{ $semanaRealTexto }}
                        @if ($abiertoMs) <span>al cargar la página</span> @endif
                        · Carga administrativa: {{ $semanaCargaTexto }} · Previsión administrativa: {{ $semanaPrevisionTexto }}. Las cargas son estimaciones, no fichajes reales.
                    </p>
                @else
                    <p class="espacio-kicker">Horas registradas · {{ $semanaRango }}</p>
                    <p class="secundario semana-nota">El total reúne las horas registradas de esta semana. Abre Mi registro para revisar cada día.</p>
                @endif
            </section>

            <section class="tarjeta">
                <div class="tarjeta-cabeza">
                    <h2>Tramos de hoy</h2>
                    @if ($tramosAbiertos > 0)
                        <x-chip tipo="en-curso">{{ $tramosAbiertos === 1 ? '1 abierto' : $tramosAbiertos.' abiertos' }}</x-chip>
                    @endif
                </div>
                @if ($tramosHoy === [])
                    <p class="secundario">Todavía no hay tramos hoy.</p>
                @else
                    <ul class="tramos">
                        @foreach ($tramosHoy as $tramo)
                            <li>
                                <span class="jornada-marca marca-{{ $tramo['tipo'] === 'pausa' ? 'pausa' : 'trabajando' }}" aria-hidden="true">
                                    @if ($tramo['tipo'] === 'pausa')
                                        <x-icono nombre="pause" />
                                    @else
                                        <x-icono nombre="play" />
                                    @endif
                                </span>
                                <span>
                                    <strong>{{ $tramo['titulo'] }}</strong>
                                    <small>{{ $tramo['detalle'] }}</small>
                                </span>
                                <span class="tramo-horas">{{ $tramo['horas'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>

    @if (($banner['accion'] ?? null) === 'cierre')
        <dialog class="dialogo-hoy" data-dialogo="otra-hora" aria-labelledby="titulo-otra-hora">
            <div class="dialogo-cabecera">
                <h2 id="titulo-otra-hora">Terminé a otra hora</h2>
                <form method="dialog">
                    <button class="dialogo-cerrar" type="submit">Cerrar</button>
                </form>
            </div>
            <form method="post" action="{{ route('jornada.cerrar') }}" class="formulario">
                @csrf
                <x-campo-hora id="hora-otra" name="hora" :max="now()->format('H:i')" />
                <button class="boton boton-principal" type="submit">Terminé a otra hora</button>
            </form>
        </dialog>
    @endif

    @if ($puedeEntrar && $inicioTarde)
        <dialog class="dialogo-hoy dialogo-inicio-tarde" data-dialogo="inicio-tarde" aria-labelledby="titulo-inicio-tarde">
            <div class="dialogo-cabecera">
                <h2 id="titulo-inicio-tarde">¿Cuándo empezaste?</h2>
                <form method="dialog">
                    <button class="dialogo-cerrar" type="submit">Cerrar</button>
                </form>
            </div>
            <form method="post" action="{{ route('jornada.entrada') }}" class="formulario" data-inicio-tarde>
                @csrf
                <fieldset>
                    <legend>Indica el motivo del inicio</legend>
                    <label><input type="radio" name="modo" value="prevista" required> Empecé a las {{ $horaInicioPrevista }}</label>
                    <label><input type="radio" name="modo" value="ahora"> Empiezo ahora</label>
                    <label><input type="radio" name="modo" value="otra" data-inicio-otra> Otra hora</label>
                </fieldset>
                <div data-inicio-hora hidden>
                    <label for="hora-inicio-tarde">Hora de entrada</label>
                    <x-campo-hora id="hora-inicio-tarde" name="hora" :max="now()->format('H:i')" :required="false" />
                </div>
                <button class="boton boton-principal" type="submit">Empezar jornada</button>
            </form>
            @if ($horaInicioPrevista)
                <form method="post" action="{{ route('jornada.reunion') }}" class="formulario">
                    @csrf
                    <button class="boton boton-secundario" type="submit">Estaba en una reunión desde las {{ $horaInicioPrevista }}</button>
                </form>
            @endif
        </dialog>
    @endif
@endsection
