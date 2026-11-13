@extends('layouts.app', ['ancha' => true, 'titulo' => 'Mi registro'])

@section('contenido')
    @php
        $lista = $consulta ? route('equipo.registro', $persona) : route('registro');
        $copia = $consulta ? route('equipo.csv', array_merge(['user' => $persona], request()->query())) : route('registro.csv', request()->query());
    @endphp

    <div class="registro" data-registro>
        <header class="registro-cabeza">
            <div>
                <p class="espacio-sobre">Mi jornada</p>
                <h1>{{ $consulta ? 'Registro de '.$persona->name : 'Mi registro' }}</h1>
                <p class="secundario">{{ $consulta ? 'Revisa los días de '.$persona->name.' y abre uno para consultar sus tramos.' : 'Revisa los días y abre uno para consultar sus tramos.' }}</p>
            </div>
            <a class="registro-csv" data-registro-csv href="{{ $copia }}">
                <x-icono nombre="download" />
                Descargar CSV
            </a>
        </header>

        <div class="registro-barra">
            <nav class="registro-tabs" aria-label="Periodo del registro">
                @foreach (['semana' => 'Esta semana', 'mes' => 'Este mes', 'anterior' => 'Mes anterior'] as $clave => $etiqueta)
                    <a href="{{ $lista }}?periodo={{ $clave }}" @if ($periodo === $clave) aria-current="page" @endif>{{ $etiqueta }}</a>
                @endforeach
            </nav>
            <p class="registro-rango" data-registro-rango>{{ $rango }}</p>
        </div>

        <details class="registro-filtrar" @if ($buscado && $periodo !== 'semana' && $periodo !== 'mes' && $periodo !== 'anterior') open @endif>
            <summary>
                <x-icono nombre="filter" />
                Filtrar
            </summary>
            <form method="get" action="{{ $lista }}" class="registro-filtros">
                <input type="hidden" name="periodo" value="todo">
                <div>
                    <label for="desde">Desde</label>
                    <x-fecha id="desde" name="desde" :value="$periodo === 'todo' ? $desde : ''" />
                </div>
                <div>
                    <label for="hasta">Hasta</label>
                    <x-fecha id="hasta" name="hasta" :value="$periodo === 'todo' ? $hasta : ''" />
                </div>
                <div>
                    <label for="estado">Estado</label>
                    <select id="estado" name="estado">
                        <option value="">Todos</option>
                        <option value="cerrada" @selected($estado === 'cerrada')>Cerrada</option>
                        <option value="pendiente" @selected($estado === 'pendiente')>Abierta</option>
                        <option value="tardia" @selected($estado === 'tardia')>Cierre al día siguiente</option>
                        <option value="fuera_plazo" @selected($estado === 'fuera_plazo')>Fichado fuera de plazo</option>
                        <option value="carga" @selected($estado === 'carga')>Carga administrativa</option>
                        <option value="prevision" @selected($estado === 'prevision')>Previsión administrativa</option>
                    </select>
                </div>
                <div>
                    <label for="incidencia">Incidencias</label>
                    <select id="incidencia" name="incidencia">
                        <option value="">Todas</option>
                        <option value="si" @selected($incidencia === 'si')>Con incidencias</option>
                        <option value="no" @selected($incidencia === 'no')>Sin incidencias</option>
                    </select>
                </div>
                <button class="boton boton-secundario" type="submit">Ver</button>
            </form>
        </details>

        <div class="registro-lista" data-registro-lista>
        <section class="registro-card">
            @if ($jornadas->isEmpty())
                <p class="registro-vacio">No hay jornadas en este periodo.</p>
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
                    @foreach ($jornadas as $entrada)
                        @if ($entrada['tipo'] === 'carga')
                            @php($carga = $entrada['registro'])
                            <div class="registro-fila" id="dia-{{ $carga->fecha->toDateString() }}" role="row">
                                <span role="cell">{{ ucfirst($carga->fecha->copy()->locale('es')->isoFormat('dddd, D MMM')) }}</span>
                                <span role="cell" class="registro-tramos">{{ $tabla->horarioCarga($carga) }} <small>(estimado)</small></span>
                                <span role="cell" class="registro-barras" aria-hidden="true"></span>
                                <span role="cell" class="registro-total">{{ \App\Support\Tiempo::corto($carga->minutos) }}</span>
                                <span role="cell" class="registro-estados"><x-chip tipo="mensaje">{{ $tabla->etiquetaCarga($carga) }}</x-chip></span>
                                <span role="cell"></span>
                            </div>
                        @else
                        @php($jornada = $entrada['registro'])
                        @php($fila = $tabla->presentacion($persona, $jornada))
                        <a class="registro-fila" id="dia-{{ $jornada->work_date->toDateString() }}" role="row" href="{{ route('registro.show', $jornada) }}">
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
                            </span>
                            <span role="cell" class="registro-flecha" aria-hidden="true">›</span>
                        </a>
                        @endif
                    @endforeach
                </div>
            @endif
            <div class="registro-pie">
                <span>{{ $jornadas->total() === 1 ? '1 registro' : $jornadas->total().' registros' }}</span>
                <span>Las correcciones conservan el historial</span>
            </div>
        </section>

        @if ($jornadas->hasPages())
            <p class="paginas">
                @if ($jornadas->previousPageUrl())
                    <a href="{{ $jornadas->previousPageUrl() }}">Días más recientes</a>
                @endif
                <span>Página {{ $jornadas->currentPage() }} de {{ $jornadas->lastPage() }}</span>
                @if ($jornadas->nextPageUrl())
                    <a href="{{ $jornadas->nextPageUrl() }}">Días anteriores</a>
                @endif
            </p>
        @endif
        </div>

        @if ($explicaciones->isNotEmpty())
            <h2>Explicaciones</h2>
            <ul class="lista lista-incidencias">
                @foreach ($explicaciones as $explicacion)
                    <li class="incidencia-fila">
                        <span>{{ $explicacion->body }}</span>
                        <span class="secundario">{{ \App\Support\Tiempo::fecha($explicacion->created_at) }}@if ($explicacion->work_date) · {{ \App\Support\Tiempo::fecha($explicacion->work_date) }}@endif</span>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($consulta && $consultas->isNotEmpty())
            <h2>Consultas</h2>
            <ul class="lista lista-incidencias">
                @foreach ($consultas as $visita)
                    <li class="incidencia-fila">
                        <span>{{ $visita->viewer->name }}@if ($visita->reason). {{ $visita->reason }}@endif</span>
                        <span class="secundario">{{ \App\Support\Tiempo::fecha($visita->created_at) }} {{ $visita->created_at->format('H:i') }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <script>
        (function () {
            var raiz = document.querySelector('[data-registro]');
            if (!raiz) {
                return;
            }

            function aplicar(doc) {
                var origen = doc.querySelector('[data-registro]');
                var lista = raiz.querySelector('[data-registro-lista]');
                var listaNueva = origen && origen.querySelector('[data-registro-lista]');
                if (!origen || !lista || !listaNueva) {
                    return false;
                }
                lista.replaceWith(listaNueva);
                var rango = raiz.querySelector('[data-registro-rango]');
                var rangoNuevo = origen.querySelector('[data-registro-rango]');
                if (rango && rangoNuevo) {
                    rango.textContent = rangoNuevo.textContent;
                }
                var csv = raiz.querySelector('[data-registro-csv]');
                var csvNuevo = origen.querySelector('[data-registro-csv]');
                if (csv && csvNuevo) {
                    csv.setAttribute('href', csvNuevo.getAttribute('href'));
                }
                var actuales = raiz.querySelectorAll('.registro-tabs a');
                var nuevas = origen.querySelectorAll('.registro-tabs a');
                actuales.forEach(function (tab, i) {
                    if (!nuevas[i]) {
                        return;
                    }
                    if (nuevas[i].hasAttribute('aria-current')) {
                        tab.setAttribute('aria-current', 'page');
                    } else {
                        tab.removeAttribute('aria-current');
                    }
                });
                return true;
            }

            var peticion = 0;

            function cargar(url, historial) {
                var id = ++peticion;
                var lista = raiz.querySelector('[data-registro-lista]');
                if (lista) {
                    lista.setAttribute('aria-busy', 'true');
                }
                return fetch(url, { headers: { 'Accept': 'text/html' } })
                    .then(function (respuesta) {
                        if (!respuesta.ok) {
                            throw new Error();
                        }
                        return respuesta.text();
                    })
                    .then(function (html) {
                        if (id !== peticion) {
                            return;
                        }
                        var doc = new DOMParser().parseFromString(html, 'text/html');
                        if (!aplicar(doc)) {
                            throw new Error();
                        }
                        if (historial) {
                            history.pushState({ registro: true }, '', url);
                        }
                    })
                    .catch(function () {
                        if (id === peticion) {
                            window.location.href = url;
                        }
                    })
                    .finally(function () {
                        if (id !== peticion) {
                            return;
                        }
                        var actual = raiz.querySelector('[data-registro-lista]');
                        if (actual) {
                            actual.removeAttribute('aria-busy');
                        }
                    });
            }

            raiz.addEventListener('click', function (evento) {
                var tab = evento.target.closest('.registro-tabs a');
                if (!tab || evento.button !== 0 || evento.metaKey || evento.ctrlKey || evento.shiftKey || evento.altKey) {
                    return;
                }
                evento.preventDefault();
                if (tab.getAttribute('aria-current') === 'page') {
                    return;
                }
                cargar(tab.href, true);
            });

            window.addEventListener('popstate', function () {
                if (!document.querySelector('[data-registro]')) {
                    return;
                }
                cargar(window.location.href, false);
            });
        })();
    </script>
@endsection
