@extends('layouts.app', ['titulo' => 'Calendario laboral', 'miga' => ['texto' => 'Gestión', 'url' => route('gestion.index')]])

@section('contenido')
    @php
        $fuentes = collect($ultima?->fuentes ?? [])->merge($tramos->flatMap(fn ($tramo) => $tramo->importacion->fuentes ?? []))->unique('url');
        $dias = $diasAplicados->map(fn ($dia) => ['date' => $dia->holiday_date->toDateString(), 'name' => $dia->name, 'ambito' => $dia->ambito]);
        $municipioTexto = $municipioElegido ? $municipioElegido['nombre'].' · '.$municipioElegido['provincia'] : '';
    @endphp
    <header class="cal-carga-cabeza">
        <div><p class="espacio-sobre">Gestión · {{ $centro->name }}</p><h1>Calendario laboral</h1><p class="secundario">Elige el municipio y desde cuándo usar sus festivos.</p></div>
        <a class="boton boton-secundario" href="{{ route('calendario', ['anio' => $anio]) }}">Ver mi calendario</a>
    </header>

    @if ($avisoCatalogo)
        <p class="franja" role="status">{{ $avisoCatalogo }}</p>
    @endif
    <section class="cal-carga-panel" aria-labelledby="titulo-cal-carga">
        <h2 id="titulo-cal-carga">Cargar o actualizar</h2>
        <form method="post" action="{{ route('gestion.calendario.cargar', $centro) }}" class="cal-carga-form" data-cal-carga-form>
            @csrf
            <div class="cal-form-campo"><label for="cal-anio">Año</label><input id="cal-anio" type="number" name="anio" value="{{ $anio }}" min="2020" max="{{ now()->year + 5 }}" required @if ($errors->has('anio')) aria-invalid="true" aria-describedby="cal-anio-error" @endif>@error('anio')<p id="cal-anio-error" class="cal-error">{{ $message }}</p>@enderror</div>
            <div class="cal-municipio" data-municipio-buscador data-url="{{ route('gestion.municipios') }}">
                <label for="cal-municipio">Municipio</label>
                <input id="cal-municipio" type="search" value="{{ $municipioTexto }}" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="cal-opciones" aria-describedby="cal-municipio-ayuda{{ $errors->has('municipio_ine') ? ' cal-municipio-error' : '' }}" placeholder="Escribe al menos dos letras" required @if ($errors->has('municipio_ine')) aria-invalid="true" @endif>
                <input type="hidden" name="municipio_ine" value="{{ $municipioElegido['ine'] ?? '' }}" data-municipio-ine>
                <div id="cal-opciones" class="cal-municipio-opciones" role="listbox" aria-label="Municipios encontrados" hidden></div>
                <p class="cal-ayuda" id="cal-municipio-ayuda" aria-live="polite" data-municipio-mensaje>{{ $municipioElegido ? $municipioElegido['provincia'].' · '.$municipioElegido['comunidad'].' · España' : 'Selecciona el municipio en los resultados.' }}</p>
                @error('municipio_ine')<p id="cal-municipio-error" class="cal-error">{{ $message }}</p>@enderror
            </div>
            <fieldset class="cal-vigencia-form" @if ($errors->has('vigencia_modo')) aria-invalid="true" aria-describedby="cal-modo-error" @endif>
                <legend>Cuándo empieza</legend>
                <label><input type="radio" name="vigencia_modo" value="anio" @checked($modoVigencia === 'anio')> Todo el año, desde el 1 de enero</label>
                <label><input type="radio" name="vigencia_modo" value="fecha" @checked($modoVigencia === 'fecha')> Desde una fecha</label>
                <label for="cal-vigente-desde">Empieza el<input id="cal-vigente-desde" type="date" name="vigente_desde" value="{{ $fechaVigencia }}" min="{{ $anio }}-01-01" max="{{ $anio }}-12-31" aria-describedby="cal-vigencia-ayuda{{ $errors->has('vigente_desde') ? ' cal-vigencia-error' : '' }}" @disabled($modoVigencia === 'anio') @required($modoVigencia === 'fecha') @if ($errors->has('vigente_desde')) aria-invalid="true" @endif></label>
                <p id="cal-vigencia-ayuda" class="cal-ayuda">Las fechas anteriores mantienen su calendario.</p>
                @error('vigencia_modo')<p id="cal-modo-error" class="cal-error">{{ $message }}</p>@enderror
                @error('vigente_desde')<p id="cal-vigencia-error" class="cal-error">{{ $message }}</p>@enderror
            </fieldset>
            <button class="boton boton-principal" type="submit" data-cal-carga-boton>Cargar / actualizar</button>
            <p class="cal-ayuda cal-carga-progreso" role="status" data-cal-carga-progreso hidden>Buscando las fiestas…</p>
        </form>
        <details class="cal-cambiar-centro"><summary>Calendario de {{ $centro->name }} · Cambiar centro</summary><nav aria-label="Elegir centro">@foreach ($centros as $otroCentro)<a href="{{ route('gestion.calendario.mostrar', ['centro' => $otroCentro, 'anio' => $anio]) }}" @if ($otroCentro->id === $centro->id) aria-current="page" @endif>{{ $otroCentro->name }}</a>@endforeach</nav></details>
    </section>

    @if ($ultima)
        <section class="cal-carga-panel cal-carga-resultado" aria-labelledby="titulo-cal-resultado">
            <header><h2 id="titulo-cal-resultado">{{ $etiquetasConsulta[$ultima->estado] ?? 'Última consulta' }}</h2><time class="secundario" datetime="{{ $ultima->consultado_at?->toIso8601String() }}">{{ $ultima->consultado_at?->format('d/m/Y H:i') }}</time></header>
            @unless (session('ok') === $resumenConsulta || session('aviso') === $resumenConsulta)<p>{{ $resumenConsulta }}</p>@endunless
            @if ($ultima->municipio)
                <p class="cal-ayuda">{{ $ultima->municipio }} · {{ $ultima->provincia }} · {{ $ultima->anio }} · Desde el {{ $ultima->vigente_desde?->format('d/m/Y') ?? '01/01/'.$ultima->anio }}</p>
            @endif
            @if ($ultima->puedeConfirmarse())
                <details class="cal-revision-panel"><summary>Revisar fechas y guardar</summary>
                    <p>Revisa las fuentes oficiales. Al confirmar, este municipio se aplicará desde el {{ $ultima->vigente_desde?->format('d/m/Y') ?? '01/01/'.$ultima->anio }}; las fechas anteriores mantendrán su calendario.</p>
                    @if ($fuentes->isNotEmpty())<p><a href="#cal-fuentes" data-cal-ver-fuentes>Ver fuentes oficiales</a></p>@endif
                    <div class="cal-tabla-contenedor" role="region" aria-label="Fechas para revisar" tabindex="0"><table class="cal-carga-tabla"><caption class="sr">Fiestas que se aplicarán a partir de la fecha elegida</caption><thead><tr><th scope="col">Fecha</th><th scope="col">Festividad</th><th scope="col">Tipo</th></tr></thead><tbody>@foreach ($ultima->festivos ?? [] as $dia)@if ($dia['date'] >= ($ultima->vigente_desde?->toDateString() ?? $ultima->anio.'-01-01'))<tr><td>{{ \Illuminate\Support\Carbon::parse($dia['date'])->format('d/m/Y') }}</td><td>{{ $dia['name'] }}</td><td><x-ambito-festivo :ambito="$dia['ambito']" /></td></tr>@endif @endforeach</tbody></table></div>
                    <form method="post" action="{{ route('gestion.calendario.confirmar', ['centro' => $centro, 'importacion' => $ultima]) }}" class="formulario">
                        @csrf
                        <label class="cal-revision"><input type="checkbox" name="revision" value="1" required> He comprobado estas fechas en las fuentes oficiales.</label>
                        <button type="submit" class="boton boton-principal">Guardar estas fechas</button>
                    </form>
                </details>
            @endif
            <details class="cal-detalle" data-consulta-detalle><summary>Detalles de la consulta</summary><p class="cal-ayuda">Resultado registrado: {{ ucfirst(str_replace('_', ' ', $ultima->estado)) }}</p><p class="cal-ayuda">{{ $ultima->mensaje }}</p></details>
        </section>
    @endif

    <section class="cal-carga-panel" aria-labelledby="titulo-cal-activo">
        <header class="cal-activo-cabeza"><div><h2 id="titulo-cal-activo">Calendario activo · {{ $anio }}</h2><p class="cal-ayuda">{{ $tramos->isNotEmpty() ? 'Cada fecha usa el municipio que le corresponde.' : 'Aún no hay un calendario completo confirmado para este centro y año.' }}</p></div>@if ($tramos->isNotEmpty())<span class="pastilla">{{ $dias->count() }} fechas</span>@endif</header>
        @include('calendario.leyenda')
        @include('calendario.vigencias')
        @if ($tramos->isNotEmpty())
            <details class="cal-fechas-guardadas"><summary>Ver los festivos guardados</summary><div class="cal-tabla-contenedor" role="region" aria-label="Festivos guardados de {{ $anio }}" tabindex="0"><table class="cal-carga-tabla"><caption class="sr">Festivos guardados de {{ $anio }}</caption><thead><tr><th scope="col">Fecha</th><th scope="col">Festividad</th><th scope="col">Tipo</th></tr></thead><tbody>@foreach ($dias as $dia)<tr><td>{{ \Illuminate\Support\Carbon::parse($dia['date'])->format('d/m/Y') }}</td><td>{{ $dia['name'] }}</td><td><x-ambito-festivo :ambito="$dia['ambito']" /></td></tr>@endforeach</tbody></table></div></details>
        @else
            <p>Busca el municipio y el año para empezar. Las vacaciones aprobadas y tus fichajes se conservan.</p>
        @endif
        <p class="cal-ayuda">Aquí guardas festivos. Las vacaciones personales se gestionan por separado.</p>
    </section>

    @if ($fuentes->isNotEmpty())
        <details id="cal-fuentes" class="cal-carga-panel cal-secundario"><summary>Fuentes de las fechas</summary><ul class="cal-fuentes-lista">@foreach ($fuentes as $fuente)<li>@if (str_starts_with($fuente['url'] ?? '', 'https://'))<a href="{{ $fuente['url'] }}" target="_blank" rel="noopener noreferrer">{{ $fuente['nombre'] }}<span class="sr"> (se abre en otra pestaña)</span></a>@else{{ $fuente['nombre'] }}@endif</li>@endforeach</ul><p class="cal-ayuda">Datos de festivos.io, CC BY 4.0, y de las fuentes indicadas. Ante diferencias prevalece la publicación oficial.</p></details>
    @endif
    @if ($historial->count() > 1)
        <details class="cal-carga-panel cal-secundario"><summary>Consultas anteriores de {{ $anio }}</summary><div class="cal-tabla-contenedor" role="region" aria-label="Consultas anteriores" tabindex="0"><table class="cal-carga-tabla"><caption class="sr">Últimas diez consultas de {{ $anio }}</caption><thead><tr><th scope="col">Consulta</th><th scope="col">Municipio</th><th scope="col">Desde</th><th scope="col">Resultado</th></tr></thead><tbody>@foreach ($historial as $consulta)<tr><td>{{ $consulta->consultado_at?->format('d/m/Y H:i') }}</td><td>{{ $consulta->municipio }}</td><td>{{ $consulta->vigente_desde?->format('d/m/Y') ?? '01/01/'.$consulta->anio }}</td><td>{{ $etiquetasConsulta[$consulta->estado] ?? 'Consulta' }}</td></tr>@endforeach</tbody></table></div></details>
    @endif

    <script>
    (() => {
        const raiz = document.querySelector('[data-municipio-buscador]');
        const campo = raiz.querySelector('input[type=search]');
        const codigo = raiz.querySelector('[data-municipio-ine]');
        const opciones = raiz.querySelector('[role=listbox]');
        const mensaje = raiz.querySelector('[data-municipio-mensaje]');
        const formulario = document.querySelector('[data-cal-carga-form]');
        const anio = document.querySelector('#cal-anio');
        const fechaEfecto = document.querySelector('#cal-vigente-desde');
        function actualizarVigencia() {
            const desdeFecha = formulario.querySelector('[name=vigencia_modo]:checked').value === 'fecha';
            fechaEfecto.disabled = !desdeFecha; fechaEfecto.required = desdeFecha;
            fechaEfecto.min = anio.value + '-01-01'; fechaEfecto.max = anio.value + '-12-31';
            if (!fechaEfecto.value.startsWith(anio.value + '-')) fechaEfecto.value = fechaEfecto.min;
        }
        anio.addEventListener('input', actualizarVigencia);
        formulario.querySelectorAll('[name=vigencia_modo]').forEach(campo => campo.addEventListener('change', actualizarVigencia));
        actualizarVigencia();
        document.querySelectorAll('[data-cal-ver-fuentes]').forEach(enlace => enlace.addEventListener('click', () => {
            const fuentes = document.querySelector('#cal-fuentes');
            if (fuentes) { fuentes.open = true; fuentes.querySelector('summary').focus(); }
        }));
        let temporizador, controlador, seleccion = -1, resultados = [], numeroConsulta = 0;
        function cerrar(cancelar = false) {
            opciones.hidden = true; campo.setAttribute('aria-expanded', 'false'); campo.removeAttribute('aria-activedescendant'); seleccion = -1;
            if (cancelar) { clearTimeout(temporizador); controlador?.abort(); numeroConsulta++; campo.removeAttribute('aria-busy'); }
        }
        function elegir(fila) { campo.value = fila.nombre + ' · ' + fila.provincia; codigo.value = fila.ine; campo.setCustomValidity(''); mensaje.textContent = fila.provincia + ' · ' + fila.comunidad + ' · España'; cerrar(true); campo.focus(); }
        campo.addEventListener('input', () => {
            clearTimeout(temporizador); if (controlador) controlador.abort();
            const id = ++numeroConsulta; codigo.value = ''; campo.setCustomValidity(''); campo.removeAttribute('aria-busy'); cerrar(); resultados = []; opciones.replaceChildren();
            if (campo.value.trim().length < 2) { mensaje.textContent = 'Escribe al menos dos letras y selecciona un resultado.'; return; }
            mensaje.textContent = 'Buscando municipios…'; campo.setAttribute('aria-busy', 'true');
            temporizador = setTimeout(async () => {
                controlador = new AbortController();
                try {
                    const url = new URL(raiz.dataset.url, location.href); url.searchParams.set('buscar', campo.value.trim());
                    const respuesta = await fetch(url, {headers: {'Accept': 'application/json'}, signal: controlador.signal});
                    if (!respuesta.ok) throw new Error();
                    const datos = await respuesta.json(); if (id !== numeroConsulta) return;
                    resultados = datos.municipios; opciones.replaceChildren();
                    resultados.forEach((fila, indice) => {
                        const opcion = document.createElement('button'); opcion.type = 'button'; opcion.tabIndex = -1; opcion.id = 'cal-opcion-' + indice; opcion.setAttribute('role', 'option'); opcion.setAttribute('aria-selected', 'false'); opcion.textContent = fila.nombre + ' · ' + fila.provincia; opcion.addEventListener('click', () => elegir(fila)); opciones.append(opcion);
                    });
                    opciones.hidden = resultados.length === 0; campo.setAttribute('aria-expanded', resultados.length ? 'true' : 'false');
                    mensaje.textContent = resultados.length ? (datos.hay_mas ? 'Hay más municipios. Escribe más letras para afinar la búsqueda.' : 'Selecciona un municipio de la lista.') : 'No se han encontrado municipios con ese texto.';
                } catch (error) { if (error.name !== 'AbortError' && id === numeroConsulta) mensaje.textContent = 'No se pueden buscar municipios ahora. Inténtalo de nuevo.'; }
                finally { if (id === numeroConsulta) campo.removeAttribute('aria-busy'); }
            }, 250);
        });
        campo.addEventListener('keydown', (evento) => {
            if (evento.key === 'Escape') { evento.preventDefault(); cerrar(true); return; }
            if (evento.key === 'Tab') { cerrar(true); return; }
            if (opciones.hidden) {
                if (['ArrowDown', 'ArrowUp'].includes(evento.key) && resultados.length && !codigo.value) {
                    opciones.hidden = false; campo.setAttribute('aria-expanded', 'true');
                } else return;
            }
            if (evento.key === 'ArrowDown' || evento.key === 'ArrowUp') {
                evento.preventDefault(); seleccion = seleccion < 0 ? (evento.key === 'ArrowDown' ? 0 : resultados.length - 1) : (seleccion + (evento.key === 'ArrowDown' ? 1 : -1) + resultados.length) % resultados.length;
                Array.from(opciones.children).forEach((opcion, indice) => opcion.setAttribute('aria-selected', String(indice === seleccion)));
                campo.setAttribute('aria-activedescendant', 'cal-opcion-' + seleccion); opciones.children[seleccion].scrollIntoView({block: 'nearest'});
            } else if (evento.key === 'Enter' && seleccion >= 0) { evento.preventDefault(); elegir(resultados[seleccion]); }
        });
        document.addEventListener('click', (evento) => { if (!raiz.contains(evento.target)) cerrar(true); });
        formulario.addEventListener('submit', (evento) => {
            if (formulario.getAttribute('aria-busy') === 'true') { evento.preventDefault(); return; }
            if (!codigo.value) { evento.preventDefault(); campo.setCustomValidity('Selecciona un municipio de la lista.'); campo.reportValidity(); return; }
            cerrar(true); formulario.setAttribute('aria-busy', 'true'); formulario.querySelector('[data-cal-carga-boton]').disabled = true; formulario.querySelector('[data-cal-carga-boton]').textContent = 'Buscando…'; formulario.querySelector('[data-cal-carga-progreso]').hidden = false;
        });
        window.addEventListener('pageshow', () => {
            cerrar(true); actualizarVigencia(); formulario.removeAttribute('aria-busy'); formulario.querySelector('[data-cal-carga-boton]').disabled = false; formulario.querySelector('[data-cal-carga-boton]').textContent = 'Cargar / actualizar'; formulario.querySelector('[data-cal-carga-progreso]').hidden = true;
        });
    })();
    </script>
@endsection
