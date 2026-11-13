@extends('layouts.app', ['titulo' => 'Sistema'])

@php
    $kb = fn (?int $bytes) => $bytes === null ? 'sin archivo' : number_format($bytes / 1024, 1, ',', '.').' KB';
    $filas = fn (int $n) => $n.' '.($n === 1 ? 'fila' : 'filas');
    $integridadCorrecta = $base['integridad'] !== null && $base['integridad']['correcta'];
    $ultimaCopia = $copias[0]['fecha'] ?? 'Todavía no hay copias';
@endphp

@section('contenido')
    <div class="sistema-pagina">
        <header class="sistema-cabeza">
            <div>
                <p class="espacio-sobre">Administración técnica</p>
                <h1>Sistema</h1>
                <p class="secundario">Base de datos, copias y actualizaciones.</p>
            </div>
            <form method="post" action="{{ route('tecnico.exportar') }}" data-exportar-bbdd>
                @csrf
                <x-boton tipo="principal" tipoHtml="button" class="sistema-copia" title="Crear una copia y descargarla" data-exportar-abre>
                    <x-icono nombre="archive" />
                    Hacer una copia ahora
                </x-boton>
            </form>
        </header>

        <section class="sistema-resumen" aria-label="Estado del sistema">
            <article class="sistema-resumen-card">
                <div class="sistema-resumen-titulo">
                    <x-icono nombre="database" />
                    <span>Base de datos</span>
                </div>
                <strong>{{ $base['responde'] ? 'Responde' : 'No responde' }}</strong>
                <small>{{ $base['milisegundos'] }} ms · {{ $base['responde'] ? 'conexión correcta' : 'sin conexión' }}</small>
                <span class="sistema-estado {{ $base['responde'] ? 'sistema-estado-ok' : 'sistema-estado-error' }}">
                    <x-icono :nombre="$base['responde'] ? 'circle-check' : 'triangle-alert'" />
                    {{ $base['responde'] ? 'Correcto' : 'Revisar' }}
                </span>
            </article>

            <article class="sistema-resumen-card">
                <div class="sistema-resumen-titulo">
                    <x-icono nombre="shield-check" />
                    <span>Integridad</span>
                </div>
                <strong>{{ $base['integridad'] === null ? 'Sin comprobar' : ($integridadCorrecta ? 'Correcta' : 'Con fallos') }}</strong>
                <small>{{ $base['integridad']['texto'] ?? 'No se ha podido comprobar todavía' }}</small>
                <span class="sistema-estado {{ $integridadCorrecta ? 'sistema-estado-ok' : 'sistema-estado-aviso' }}">
                    <x-icono :nombre="$integridadCorrecta ? 'circle-check' : 'triangle-alert'" />
                    {{ $integridadCorrecta ? 'Correcto' : 'Revisar' }}
                </span>
            </article>

            <article class="sistema-resumen-card">
                <div class="sistema-resumen-titulo">
                    <x-icono nombre="archive" />
                    <span>Copias</span>
                </div>
                <strong>{{ count($copias) }} {{ count($copias) === 1 ? 'copia' : 'copias' }}</strong>
                <small>Última: {{ $ultimaCopia }}</small>
                <span class="sistema-estado sistema-estado-ok">
                    <x-icono nombre="circle-check" />
                    Correcto
                </span>
            </article>

            <article class="sistema-resumen-card">
                <div class="sistema-resumen-titulo">
                    <x-icono nombre="refresh" />
                    <span>Actualizaciones</span>
                </div>
                <strong>{{ $base['pendientes'] === [] ? 'Al día' : count($base['pendientes']).' pendientes' }}</strong>
                <small>{{ $base['aplicadas'] }} cambios aplicados</small>
                <span class="sistema-estado {{ $base['pendientes'] === [] ? 'sistema-estado-ok' : 'sistema-estado-aviso' }}">
                    <x-icono :nombre="$base['pendientes'] === [] ? 'circle-check' : 'triangle-alert'" />
                    {{ $base['pendientes'] === [] ? 'Correcto' : 'Pendiente' }}
                </span>
            </article>
        </section>

        <div class="sistema-cuerpo">
            <section class="sistema-actividad" aria-labelledby="sistema-actividad-titulo">
                <h2 id="sistema-actividad-titulo">Lo que se ha hecho</h2>
                @if ($acciones->isEmpty())
                    <p class="secundario sistema-vacio">Todavía no se ha hecho nada.</p>
                @else
                    <div class="sistema-tabla" role="table">
                        <div class="sistema-tabla-cabeza" role="row">
                            <span>Momento</span>
                            <span>Acción</span>
                            <span>Persona</span>
                            <span>Resultado</span>
                        </div>
                        @foreach ($acciones as $accion)
                            <div class="sistema-tabla-fila" role="row">
                                <span>{{ $accion->created_at->locale('es')->isoFormat('D MMM, HH:mm') }}</span>
                                <span>{{ $accion->accion === 'copia' ? 'Copia' : ($accion->accion === 'restauracion' ? 'Restauración' : 'Actualización') }}</span>
                                <span>{{ $accion->autor->name }}</span>
                                <span>{{ $accion->detalle }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>

            <aside class="sistema-lateral">
                <article class="sistema-panel">
                    <h2>Base de datos en uso</h2>
                    <p class="secundario">Sustitúyela por una copia guardada o por un archivo. Antes se guarda la que está ahora.</p>
                    @if ($base['pendientes'] !== [])
                        <p>Hay {{ count($base['pendientes']) }} cambios sin aplicar.</p>
                    @else
                        <p>Todo está al día.</p>
                    @endif
                    <x-boton tipo="secundario" tipoHtml="button" data-actualizar-abre>
                        <x-icono nombre="refresh" />
                        Actualizar
                    </x-boton>
                </article>

                <article class="sistema-panel">
                    <h2>Base de datos activa</h2>
                    <dl class="sistema-datos">
                        <div><dt>Motor</dt><dd>{{ strtoupper($base['driver']) }}</dd></div>
                        <div><dt>Tablas</dt><dd>{{ count($base['tablas']) }}</dd></div>
                        <div><dt>Integridad</dt><dd>{{ $base['integridad'] === null ? 'Sin comprobar' : ($integridadCorrecta ? 'Correcta' : 'Con fallos') }}</dd></div>
                        <div><dt>Tamaño</dt><dd>{{ $kb($base['bytes']) }}</dd></div>
                    </dl>
                    <span class="sr">
                        @foreach ($base['tablas'] as $tabla)
                            {{ $tabla['nombre'] }}: {{ $filas($tabla['filas']) }}.
                        @endforeach
                    </span>
                    @if ($base['error'])
                        <p class="franja" role="alert">{{ $base['error'] }}</p>
                    @endif
                </article>
            </aside>
        </div>
    </div>

    <dialog class="sistema-dialogo" data-actualizar-dialogo @if (session('restaurar') || $errors->hasAny(['confirmo', 'copia', 'archivo'])) data-abierto @endif aria-labelledby="sistema-dialogo-titulo">
        <div class="sistema-dialogo-cabeza">
            <h2 id="sistema-dialogo-titulo">Elegir la base de datos</h2>
            <button class="sistema-dialogo-cerrar" type="button" data-actualizar-cerrar aria-label="Cerrar">
                <x-icono nombre="x" />
            </button>
        </div>
        <form method="post" action="{{ route('tecnico.restaurar') }}" enctype="multipart/form-data" data-actualizar-form>
            @csrf
            <p>Elige una copia de las que ya están guardadas, o un archivo nuevo. La base actual se copia antes de sustituirla. Después hay que entrar otra vez.</p>
            @if ($copiasRestauracion === [])
                <p>Todavía no hay copias guardadas.</p>
            @else
                <div class="sistema-elegir">
                    @foreach ($copiasRestauracion as $copia)
                        <label>
                            <input type="radio" name="copia" value="{{ $copia['nombre'] }}" @checked(old('copia') === $copia['nombre'])>
                            <span>{{ $copia['fecha'] }} · {{ $kb($copia['bytes']) }}</span>
                        </label>
                    @endforeach
                </div>
            @endif
            <label class="sistema-archivo">
                <span>Un archivo nuevo</span>
                <input type="file" name="archivo" accept=".sqlite,application/x-sqlite3,application/vnd.sqlite3">
            </label>
            <label class="sistema-confirmacion">
                <input type="checkbox" name="confirmo" value="1" data-actualizar-confirmo>
                <span>Quiero sustituir la base actual por esta.</span>
            </label>
            <div class="sistema-dialogo-acciones">
                <button class="boton boton-secundario" type="button" data-actualizar-cancelar>Cancelar</button>
                <button class="boton boton-principal" type="submit" data-actualizar-confirmar disabled>Usar esta base</button>
            </div>
        </form>
    </dialog>

    <script>
        (function () {
            var formulario = document.querySelector('[data-actualizar-form]');
            var dialogo = document.querySelector('[data-actualizar-dialogo]');
            var confirmacion = document.querySelector('[data-actualizar-confirmo]');
            var confirmar = document.querySelector('[data-actualizar-confirmar]');

            if (!formulario || !dialogo || !confirmacion || !confirmar) {
                return;
            }

            function cerrarDialogo() {
                confirmacion.checked = false;
                confirmar.disabled = true;
                dialogo.close();
            }

            document.querySelector('[data-actualizar-abre]').addEventListener('click', function () {
                confirmacion.checked = false;
                confirmar.disabled = true;
                dialogo.showModal();
            });
            confirmacion.addEventListener('change', function () {
                confirmar.disabled = !confirmacion.checked;
            });
            document.querySelector('[data-actualizar-cerrar]').addEventListener('click', function () {
                cerrarDialogo();
            });
            document.querySelector('[data-actualizar-cancelar]').addEventListener('click', function () {
                cerrarDialogo();
            });
            formulario.querySelectorAll('input[name="copia"]').forEach(function (opcion) {
                opcion.addEventListener('change', function () {
                    var archivo = formulario.querySelector('input[name="archivo"]');
                    if (archivo) {
                        archivo.value = '';
                    }
                });
            });
            var archivo = formulario.querySelector('input[name="archivo"]');
            if (archivo) {
                archivo.addEventListener('change', function () {
                    if (!archivo.files || archivo.files.length === 0) {
                        return;
                    }
                    formulario.querySelectorAll('input[name="copia"]').forEach(function (opcion) {
                        opcion.checked = false;
                    });
                });
            }
            dialogo.addEventListener('click', function (evento) {
                if (evento.target === dialogo) {
                    cerrarDialogo();
                }
            });
            if (dialogo.hasAttribute('data-abierto')) {
                dialogo.showModal();
            }
        })();

        (function () {
            var formulario = document.querySelector('[data-exportar-bbdd]');
            var boton = formulario && formulario.querySelector('[data-exportar-abre]');
            if (!formulario || !boton) {
                return;
            }

            var marcoAnterior = null;

            boton.addEventListener('click', function () {
                if (marcoAnterior) {
                    marcoAnterior.remove();
                }

                var marco = document.createElement('iframe');
                marco.name = 'descarga-copia-' + Date.now();
                marco.hidden = true;
                marco.setAttribute('aria-hidden', 'true');
                document.body.appendChild(marco);
                marcoAnterior = marco;

                var envio = document.createElement('form');
                envio.method = 'post';
                envio.action = formulario.action;
                envio.target = marco.name;
                envio.hidden = true;

                var token = formulario.querySelector('input[name="_token"]');
                if (token) {
                    var campo = document.createElement('input');
                    campo.type = 'hidden';
                    campo.name = '_token';
                    campo.value = token.value;
                    envio.appendChild(campo);
                }

                document.body.appendChild(envio);
                envio.submit();
                envio.remove();
            });
        })();
    </script>
@endsection
