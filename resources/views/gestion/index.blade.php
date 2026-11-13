@extends('layouts.app', ['titulo' => 'Gestión'])

@section('contenido')
    @php
        $festivosPreview = session('festivos_preview', []);
        $festivosErrores = session('festivos_errores', []);
        $festivosPreviewPorMes = collect($festivosPreview)
            ->sortBy('fecha')
            ->groupBy(fn (array $fila) => \Illuminate\Support\Carbon::parse($fila['fecha'])->format('Y-m'));
        $festivosPreviewNacionales = collect($festivosPreview)->where('ambito', 'estado')->count();
        $festivosPreviewAutonomicos = collect($festivosPreview)->where('ambito', 'comunidad')->count();
        $festivosPreviewLocales = collect($festivosPreview)->where('ambito', 'pueblo')->count();
    @endphp
    <div class="gestion-pagina">
        <header class="gestion-cabeza">
            <p class="espacio-sobre">AbacoQD</p>
            <h1>Gestión</h1>
            <p class="gestion-intro">Sedes, calendario y solicitudes del equipo.</p>
        </header>

        <div class="gestion-tablero">
            <section class="gestion-tarjeta gestion-tarjeta-vacaciones" aria-labelledby="titulo-solicitudes-vacaciones">
                <header class="gestion-tarjeta-cabeza">
                    <span class="gestion-marca gestion-marca-vacaciones" aria-hidden="true">
                        <x-icono nombre="calendar" />
                    </span>
                    <div>
                        <h2 id="titulo-solicitudes-vacaciones">
                            <a href="{{ route('gestion.cobertura') }}">Solicitudes de vacaciones</a>
                        </h2>
                        <p>{{ $solicitudesVacacionesPendientes === 0 ? 'No hay solicitudes pendientes.' : ($solicitudesVacacionesPendientes === 1 ? '1 por decidir' : $solicitudesVacacionesPendientes.' por decidir') }}</p>
                    </div>
                    @if ($solicitudesVacacionesPendientes > 0)
                        <span class="pastilla pastilla-pendiente">{{ $solicitudesVacacionesPendientes }}</span>
                    @endif
                </header>
            </section>

            <section class="gestion-tarjeta gestion-tarjeta-sedes" aria-labelledby="titulo-sedes">
                <header class="gestion-tarjeta-cabeza">
                    <span class="gestion-marca gestion-marca-sedes" aria-hidden="true">
                        <x-icono nombre="map-pin" />
                    </span>
                    <div>
                        <h2 id="titulo-sedes">{{ $centros->count() === 1 ? 'Sede principal' : 'Sedes' }}</h2>
                        @if ($centros->count() > 1)<p>{{ $centros->count() }} sedes</p>@endif
                    </div>
                </header>

                <details class="gestion-anadir-sede" @if ($centros->isEmpty() || $errors->has('name')) open @endif>
                <summary>{{ $centros->isEmpty() ? 'Crear sede' : 'Añadir otra sede' }}</summary>
                <form method="post" action="{{ route('equipo.centros.store') }}" class="gestion-sede-alta">
                    @csrf
                    <label for="centro-name">Nombre de la sede</label>
                    <input id="centro-name" name="name" value="{{ old('name') }}" maxlength="120" placeholder="Nombre de la sede" required>
                    @include('gestion.municipio-selector', ['sedeMunicipio' => null, 'prefijo' => 'centro-nuevo'])
                    <button class="boton boton-principal" type="submit">Guardar sede</button>
                </form>
                </details>

                @if ($estadoCatalogo['disponible'] ?? false)
                    <details class="cal-csv-alternativa">
                        <summary>Información del catálogo de municipios</summary>
                        <p class="cal-ayuda">{{ number_format($estadoCatalogo['total'], 0, ',', '.') }} municipios disponibles para elegir la sede.</p>
                        <p class="cal-ayuda">Copia actualizada el {{ \Illuminate\Support\Carbon::parse($estadoCatalogo['actualizado_at'], 'UTC')->timezone(config('app.timezone'))->format('d/m/Y H:i') }}. {{ $estadoCatalogo['fuente'] }}. Distribuido por <a href="{{ $estadoCatalogo['origen'] }}" target="_blank" rel="noopener noreferrer">festivos.io</a>; <a href="{{ $estadoCatalogo['referencia'] }}" target="_blank" rel="noopener noreferrer">referencia del INE</a>.</p>
                    </details>
                @endif

                <div class="gestion-tarjeta-cuerpo">
                    @if ($centros->isEmpty())
                        <p class="gestion-vacio">
                            <x-icono nombre="map-pin" />
                            <span>Crea la primera sede para asignarla al dar de alta.</span>
                        </p>
                    @else
                        <ul class="gestion-sedes-lista">
                            @foreach ($centros as $centro)
                                <li>
                                    <div class="gestion-sede">
                                        <span class="gestion-sede-pin" aria-hidden="true">
                                            <x-icono nombre="map-pin" />
                                        </span>
                                        <span>
                                            <strong>{{ $centro->name }}</strong>
                                            <small>{{ collect([$centro->municipality, $centro->province, $centro->community])->filter()->unique()->join(' · ') }}</small>
                                        </span>
                                    </div>
                                    <a class="gestion-sede-calendario" href="{{ route('gestion.calendario.mostrar', $centro) }}">Calendario</a>
                                    <details @if ($errors->has('vigente_desde') && (string) old('_centro') === (string) $centro->id) open @endif>
                                        <summary>Editar</summary>
                                        <form method="post" action="{{ route('equipo.centros.update', $centro) }}" class="formulario" data-sede-editar data-ine-original="{{ $centro->municipio_ine }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="_centro" value="{{ $centro->id }}">
                                            <label>Nombre <input name="name" value="{{ $centro->name }}" maxlength="120" required></label>
                                            @include('gestion.municipio-selector', ['sedeMunicipio' => $centro, 'prefijo' => 'centro-'.$centro->id])
                                            <button class="boton boton-secundario" type="submit">Guardar cambios</button>
                                            <dialog class="dialogo-hoy sede-calendario-dialogo" data-sede-calendario-dialogo @if ($errors->has('vigente_desde') && (string) old('_centro') === (string) $centro->id) data-abierto @endif aria-labelledby="titulo-calendario-sede-{{ $centro->id }}">
                                                <h2 id="titulo-calendario-sede-{{ $centro->id }}" class="sr">Calendario del nuevo municipio</h2>
                                                <div class="sede-calendario-fecha">
                                                    <label for="vigente-sede-{{ $centro->id }}">Empieza el
                                                        <input id="vigente-sede-{{ $centro->id }}" type="date" name="vigente_desde" value="{{ (string) old('_centro') === (string) $centro->id ? old('vigente_desde', now()->toDateString()) : now()->toDateString() }}" min="2020-01-01" max="{{ now()->year + 5 }}-12-31" data-sede-vigente-desde>
                                                    </label>
                                                    <p class="cal-ayuda">Las fechas anteriores mantienen su calendario.</p>
                                                    @if ($errors->has('vigente_desde') && (string) old('_centro') === (string) $centro->id)
                                                        <p class="cal-error" role="alert">{{ $errors->first('vigente_desde') }}</p>
                                                    @endif
                                                </div>
                                                <div class="sede-calendario-acciones">
                                                    <button class="boton boton-secundario" type="button" data-sede-calendario-cerrar>Cancelar</button>
                                                    <button class="boton boton-principal" type="submit" data-sede-calendario-aceptar>Aceptar</button>
                                                </div>
                                            </dialog>
                                        </form>
                                    </details>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </section>

            <section class="gestion-tarjeta gestion-tarjeta-festivos" aria-labelledby="titulo-gestion-festivos">
                <header class="gestion-tarjeta-cabeza">
                    <span class="gestion-marca gestion-marca-festivos" aria-hidden="true">
                        <x-icono nombre="archive" />
                    </span>
                    <div>
                        <h2 id="titulo-gestion-festivos">Festivos</h2>
                        <p>Calendario laboral por sede y año</p>
                    </div>
                </header>

                @if ($centros->isNotEmpty())
                    <div class="cal-gestion-acceso">
                        <p>Carga los festivos y elige desde qué día se aplican.</p>
                        <a class="boton boton-principal" href="{{ route('gestion.calendario.mostrar', auth()->user()->centro_id ?? $centros->first()->id) }}">Gestionar calendario</a>
                    </div>
                @endif
                <details class="cal-csv-alternativa"><summary>Importar por archivo</summary>
                <p class="cal-ayuda">Para calendarios propios o ajustes especiales. <a href="{{ route('calendario.festivos.plantilla') }}">Descargar plantilla CSV</a></p>
                <form method="post" action="{{ route('calendario.festivos.previsualizar') }}" enctype="multipart/form-data" class="gestion-festivos-form" data-festivos-form>
                    @csrf
                    <input id="archivo-festivos" name="archivo" type="file" accept=".csv,.txt" required>
                    <label class="gestion-festivos-zona" for="archivo-festivos">
                        <x-icono nombre="upload" />
                        <strong>Seleccionar archivo</strong>
                        <span>Fichero CSV</span>
                        <span class="calendario-archivo-nombre" data-archivo-nombre>Ningún archivo seleccionado</span>
                    </label>
                    <p class="calendario-archivo-error" data-archivo-error role="alert" hidden>Selecciona un archivo CSV o TXT.</p>
                </form>
                </details>

                @if ($festivosGestion->isNotEmpty())
                    <footer class="gestion-tarjeta-pie">
                        <button class="boton boton-secundario calendario-festivos-cargados" type="button" data-festivos-cargados-abrir>
                            <x-icono nombre="archive" />
                            <span>Ver festivos cargados</span>
                        </button>
                    </footer>
                @endif

                @if ($festivosErrores !== [] || $festivosPreview !== [])
                    <dialog class="dialogo-hoy calendario-festivos-dialogo calendario-festivos-preview-dialogo" data-festivos-dialogo data-abierto aria-labelledby="titulo-festivos-preview">
                        <div class="dialogo-cabecera">
                            <div>
                                <h2 id="titulo-festivos-preview">Vista previa</h2>
                                <p>
                                    @if ($festivosPreview === [])
                                        El archivo tiene filas que no se pueden guardar.
                                    @elseif (count($festivosPreview) === 1)
                                        1 festivo listo para guardar en el calendario.
                                    @else
                                        {{ count($festivosPreview) }} festivos listos para guardar en el calendario.
                                    @endif
                                </p>
                            </div>
                        </div>

                        @if ($festivosPreview !== [])
                            <p class="calendario-festivos-resumen">
                                @if ($festivosPreviewNacionales > 0)
                                    <span>{{ $festivosPreviewNacionales === 1 ? '1 nacional' : $festivosPreviewNacionales.' nacionales' }}</span>
                                @endif
                                @if ($festivosPreviewAutonomicos > 0)
                                    <span>{{ $festivosPreviewAutonomicos === 1 ? '1 autonómico' : $festivosPreviewAutonomicos.' autonómicos' }}</span>
                                @endif
                                @if ($festivosPreviewLocales > 0)
                                    <span>{{ $festivosPreviewLocales === 1 ? '1 local' : $festivosPreviewLocales.' locales' }}</span>
                                @endif
                            </p>
                        @endif

                        @if ($festivosErrores !== [])
                            <div class="franja" role="alert">
                                <strong>Estas filas no se importarán</strong>
                                <ul>
                                    @foreach ($festivosErrores as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if ($festivosPreview !== [])
                            <div class="calendario-festivos-preview">
                                @foreach ($festivosPreviewPorMes as $mesClave => $festivosDelMes)
                                    @php
                                        $mesFecha = \Illuminate\Support\Carbon::parse($mesClave.'-01')->locale('es');
                                    @endphp
                                    <section class="calendario-festivos-mes" aria-label="{{ ucfirst($mesFecha->isoFormat('MMMM YYYY')) }}">
                                        <h3>{{ ucfirst($mesFecha->isoFormat('MMMM YYYY')) }}</h3>
                                        <ul>
                                            @foreach ($festivosDelMes as $festivoPreview)
                                                @php
                                                    $diaFestivo = \Illuminate\Support\Carbon::parse($festivoPreview['fecha'])->locale('es');
                                                    $ambitoFestivo = match ($festivoPreview['ambito']) {
                                                        'estado' => 'Nacional',
                                                        'comunidad' => 'Autonómico',
                                                        'pueblo' => 'Local',
                                                        default => $festivoPreview['ambito'],
                                                    };
                                                    $lugarFestivo = match ($festivoPreview['ambito']) {
                                                        'comunidad' => $festivoPreview['community'] ?? '',
                                                        'pueblo' => $festivoPreview['municipio'] ?? '',
                                                        default => '',
                                                    };
                                                @endphp
                                                <li class="calendario-festivo-fila">
                                                    <span class="calendario-festivo-dia">
                                                        <strong>{{ $diaFestivo->format('j') }}</strong>
                                                        <span>{{ $diaFestivo->isoFormat('ddd') }}</span>
                                                    </span>
                                                    <span class="calendario-festivo-datos">
                                                        <strong>{{ $festivoPreview['nombre'] }}</strong>
                                                        @if ($lugarFestivo !== '')
                                                            <span>{{ $lugarFestivo }}</span>
                                                        @endif
                                                    </span>
                                                    <span class="festivo-ambito festivo-ambito-{{ $festivoPreview['ambito'] }}">{{ $ambitoFestivo }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </section>
                                @endforeach
                            </div>
                        @endif

                        <div class="calendario-festivos-acciones">
                            <form method="post" action="{{ route('calendario.festivos.previsualizacion.cerrar') }}">
                                @csrf
                                <x-boton tipo="secundario" tipoHtml="submit">Cancelar</x-boton>
                            </form>
                            @if ($festivosPreview !== [])
                                <form method="post" action="{{ route('calendario.festivos.importar') }}">
                                    @csrf
                                    <x-boton tipo="principal" tipoHtml="submit">
                                        {{ count($festivosPreview) === 1 ? 'Guardar festivo' : 'Guardar '.count($festivosPreview).' festivos' }}
                                    </x-boton>
                                </form>
                            @endif
                        </div>
                    </dialog>
                @endif

                @if ($festivosGestion->isNotEmpty())
                    <dialog class="dialogo-hoy calendario-festivos-dialogo" data-festivos-cargados-dialogo aria-labelledby="titulo-festivos-cargados">
                        <div class="dialogo-cabecera">
                            <h2 id="titulo-festivos-cargados">Festivos cargados</h2>
                            <form method="dialog">
                                <button class="dialogo-cerrar calendario-dialogo-cerrar" type="submit" aria-label="Cerrar" title="Cerrar">
                                    <x-icono nombre="x" />
                                </button>
                            </form>
                        </div>
                        <div class="calendario-festivos-lista">
                            @foreach ($festivosGestion as $festivo)
                                <details>
                                    <summary>{{ $festivo->holiday_date->format('d/m/Y') }} · {{ $festivo->name }}</summary>
                                    <form method="post" action="{{ route('calendario.festivos.update', $festivo) }}" class="formulario">
                                        @csrf
                                        @method('PATCH')
                                        <label>Fecha <input name="holiday_date" type="date" value="{{ $festivo->holiday_date->toDateString() }}" required></label>
                                        <label>Nombre <input name="name" value="{{ $festivo->name }}" required maxlength="120"></label>
                                        <label>Ámbito
                                            <select name="ambito" required>
                                                <option value="estado" @selected($festivo->ambito === 'estado')>Nacional</option>
                                                <option value="comunidad" @selected($festivo->ambito === 'comunidad')>Autonómico</option>
                                                <option value="pueblo" @selected($festivo->ambito === 'pueblo')>Local</option>
                                            </select>
                                        </label>
                                        <label>Autonomía <input name="community" value="{{ $festivo->community }}" maxlength="120"></label>
                                        <label>Sede local
                                            <select name="centro">
                                                <option value="">Ninguna</option>
                                                @foreach ($centros as $opcionCentro)
                                                    <option value="{{ $opcionCentro->name }}" @selected($festivo->centro_id === $opcionCentro->id)>{{ $opcionCentro->etiqueta() }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <button class="boton boton-secundario" type="submit">Guardar cambios</button>
                                    </form>
                                    <form method="post" action="{{ route('calendario.festivos.destroy', $festivo) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="boton boton-secundario" type="submit">Eliminar festivo</button>
                                    </form>
                                </details>
                            @endforeach
                        </div>
                    </dialog>
                @endif
            </section>
        </div>
    </div>

    <script>
        (function () {
            var dialogoFestivos = document.querySelector('[data-festivos-dialogo]');
            var abrirFestivosCargados = document.querySelector('[data-festivos-cargados-abrir]');
            var dialogoFestivosCargados = document.querySelector('[data-festivos-cargados-dialogo]');
            var archivo = document.querySelector('#archivo-festivos');
            var nombre = document.querySelector('[data-archivo-nombre]');
            var error = document.querySelector('[data-archivo-error]');
            var formulario = document.querySelector('[data-festivos-form]');

            if (dialogoFestivos && dialogoFestivos.hasAttribute('data-abierto')) {
                dialogoFestivos.showModal();
            }

            if (abrirFestivosCargados && dialogoFestivosCargados) {
                abrirFestivosCargados.addEventListener('click', function () {
                    dialogoFestivosCargados.showModal();
                });
            }

            if (!archivo || !nombre || !error || !formulario) {
                return;
            }

            function esCsv() {
                var fichero = archivo.files[0];
                return fichero && /\.(csv|txt)$/i.test(fichero.name);
            }

            function validar() {
                var valido = !archivo.files[0] || esCsv();
                error.hidden = valido;
                archivo.setCustomValidity(valido ? '' : 'Selecciona un archivo CSV o TXT.');
                return valido;
            }

            archivo.addEventListener('change', function () {
                if (!validar()) {
                    archivo.value = '';
                    nombre.textContent = 'Ningún archivo seleccionado';
                    return;
                }

                nombre.textContent = archivo.files[0].name;
                formulario.submit();
            });
        })();
    </script>
    <script src="{{ asset('js/municipios-sedes.js') }}?v={{ filemtime(public_path('js/municipios-sedes.js')) }}"></script>
    <script>
        document.querySelectorAll('[data-sede-editar]').forEach(function (formulario) {
            var dialogo = formulario.querySelector('[data-sede-calendario-dialogo]');
            var fecha = formulario.querySelector('[data-sede-vigente-desde]');
            var ine = formulario.querySelector('[data-sede-ine]');
            if (!dialogo || !fecha || !ine) {
                return;
            }

            var aceptar = formulario.querySelector('[data-sede-calendario-aceptar]');

            formulario.addEventListener('submit', function (evento) {
                var guardando = evento.submitter === aceptar || formulario.dataset.aceptarCalendario === '1';
                if (guardando) {
                    delete formulario.dataset.aceptarCalendario;
                    if (!fecha.value) {
                        evento.preventDefault();
                        fecha.setCustomValidity('Indica desde qué día se usará este municipio.');
                        fecha.reportValidity();
                        return;
                    }
                    fecha.setCustomValidity('');
                    return;
                }
                if (ine.value === (formulario.dataset.ineOriginal || '')) {
                    return;
                }
                evento.preventDefault();
                delete formulario.dataset.enviando;
                dialogo.showModal();
                fecha.focus();
            });

            formulario.querySelector('[data-sede-calendario-cerrar]').addEventListener('click', function () {
                delete formulario.dataset.enviando;
                dialogo.close();
            });
            dialogo.addEventListener('close', function () {
                delete formulario.dataset.enviando;
            });

            aceptar.addEventListener('click', function () {
                formulario.dataset.aceptarCalendario = '1';
            });

            fecha.addEventListener('input', function () {
                fecha.setCustomValidity('');
            });

            if (dialogo.hasAttribute('data-abierto')) {
                dialogo.showModal();
            }
        });
    </script>
@endsection
