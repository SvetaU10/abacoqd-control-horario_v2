<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $titulo ?? 'Control horario' }} · AbacoQD</title>
    <meta name="description" content="Registro de jornada, calendario y gestión del equipo de AbacoQD.">
    <script>
        (function () {
            var guardado = null;
            try { guardado = localStorage.getItem('abaco-tema'); } catch (e) {}
            var oscuro = guardado === 'oscuro' || (guardado !== 'claro' && window.matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.setAttribute('data-tema', oscuro ? 'oscuro' : 'claro');
        })();
    </script>
    <link rel="icon" href="{{ asset('marca/logos/abacoqd_isotipo.svg') }}" type="image/svg+xml">
    <style>@layer reset, tokens, base, componentes, pantallas;</style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body @class(['es-jefa' => auth()->check() && auth()->user()->esJefa()]) @if ($pendiente ?? false) data-pendiente="1" @endif>
    <a class="saltar" href="#contenido">Saltar al contenido</a>

    @auth
        @php
            $partes = preg_split('/\s+/', trim(auth()->user()->name)) ?: [];
            $iniciales = mb_strtoupper(mb_substr($partes[0] ?? '', 0, 1).mb_substr($partes[1] ?? '', 0, 1));
            $papel = auth()->user()->esJefa() ? 'Responsable' : (auth()->user()->esTecnico() ? 'Técnico' : 'Trabajador');
        @endphp
        <header class="barra lateral" id="navegacion-principal">
            <button class="nav-cerrar" type="button" data-nav-cerrar><span aria-hidden="true">×</span> Cerrar menú</button>
            <a class="marca-logo" href="{{ route(auth()->user()->paginaInicio()) }}" aria-label="Control horario de AbacoQD">
                <img class="isotipo" src="{{ asset('marca/logos/abacoqd_isotipo.svg') }}" alt="">
                <span>
                    <span class="marca-nombre">Control horario</span>
                    <span class="marca-sub">AbacoQD</span>
                </span>
            </a>
            <nav class="menu" aria-label="Secciones">
                @if (auth()->user()->registraJornada())
                <p class="lateral-kicker">Mi jornada</p>
                <a href="{{ route(auth()->user()->paginaInicio()) }}" @if (request()->routeIs('jornada')) aria-current="page" @endif>
                    <x-icono nombre="clock" />
                    <span class="nav-etiqueta">Hoy</span>
                </a>
                <a href="{{ route('registro') }}" @if (request()->routeIs('registro', 'registro.show', 'registro.dia')) aria-current="page" @endif>
                    <x-icono nombre="list" />
                    <span class="nav-etiqueta">Mi registro</span>
                </a>
                <a href="{{ route('calendario') }}" @if (request()->routeIs('calendario')) aria-current="page" @endif>
                    <x-icono nombre="calendar" />
                    <span class="nav-etiqueta">Calendario</span>
                </a>
                @unless (auth()->user()->esJefa())
                    <a href="{{ route('jornada.incidencia.crear') }}" @if (request()->routeIs('jornada.incidencia.crear')) aria-current="page" @endif @if (($incidenciasNuevas ?? 0) > 0) aria-label="Incidencia, {{ $incidenciasNuevas === 1 ? '1 nueva' : $incidenciasNuevas.' nuevas' }}" @endif>
                        <x-icono nombre="inbox" />
                        <span class="nav-etiqueta">Incidencia</span>
                        @if (($incidenciasNuevas ?? 0) > 0)
                            <span class="aviso-ticket" aria-hidden="true">{{ $incidenciasNuevas }}</span>
                        @endif
                    </a>
                @endunless
                @endif
                @if (auth()->user()->esJefa())
                    <p class="lateral-kicker">Equipo</p>
                    <a href="{{ route('equipo.index') }}" @if (request()->routeIs('equipo.*')) aria-current="page" @endif>
                        <x-icono nombre="user-plus" />
                        <span class="nav-etiqueta">Personas</span>
                    </a>
                    <a href="{{ route('jefe.index') }}" @if (request()->routeIs('jefe.index', 'jefe.persona', 'jefe.registro', 'jefe.dia')) aria-current="page" @endif>
                        <x-icono nombre="users" />
                        <span class="nav-etiqueta">Estado del equipo</span>
                    </a>
                    <a href="{{ route('incidencias.index') }}" @if (request()->routeIs('incidencias.*')) aria-current="page" @endif @if (($incidenciasNuevas ?? 0) > 0) aria-label="Incidencias, {{ $incidenciasNuevas === 1 ? '1 nueva' : $incidenciasNuevas.' nuevas' }}" @endif>
                        <x-icono nombre="inbox" />
                        <span class="nav-etiqueta">Incidencias</span>
                        @if (($incidenciasNuevas ?? 0) > 0)
                            <span class="aviso-ticket" aria-hidden="true">{{ $incidenciasNuevas }}</span>
                        @endif
                    </a>
                    <a href="{{ route('jefe.consultas') }}" @if (request()->routeIs('jefe.consultas')) aria-current="page" @endif>
                        <x-icono nombre="eye" />
                        <span class="nav-etiqueta">Consultas</span>
                    </a>
                    <a href="{{ route('gestion.index') }}" @if (request()->routeIs('gestion.*')) aria-current="page" @endif @if (($vacacionesPendientes ?? 0) > 0) aria-label="Gestión, {{ $vacacionesPendientes === 1 ? '1 solicitud de vacaciones pendiente' : $vacacionesPendientes.' solicitudes de vacaciones pendientes' }}" @endif>
                        <x-icono nombre="folder" />
                        <span class="nav-etiqueta">Gestión</span>
                        @if (($vacacionesPendientes ?? 0) > 0)
                            <span class="aviso-ticket" aria-hidden="true">{{ $vacacionesPendientes }}</span>
                        @endif
                    </a>
                @endif
                @if (auth()->user()->esTecnico())
                    <a href="{{ route('tecnico.index') }}" @if (request()->routeIs('tecnico.index', 'tecnico.copia', 'tecnico.actualizar', 'tecnico.restaurar')) aria-current="page" @endif>
                        <x-icono nombre="sliders" />
                        <span class="nav-etiqueta">Sistema</span>
                    </a>
                    <a href="{{ route('tecnico.tickets') }}" @if (request()->routeIs('tecnico.tickets*', 'tickets.show')) aria-current="page" @endif @if (($ticketsNuevos ?? 0) > 0) aria-label="Tickets, {{ $ticketsNuevos === 1 ? '1 nuevo' : $ticketsNuevos.' nuevos' }}" @endif>
                        <x-icono nombre="ticket" />
                        <span class="nav-etiqueta">Tickets</span>
                        @if (($ticketsNuevos ?? 0) > 0)
                            <span class="aviso-ticket" aria-hidden="true">{{ $ticketsNuevos }}</span>
                        @endif
                    </a>
                @endif
                @unless (auth()->user()->esTecnico())
                    <div class="menu-pie">
                        <a href="{{ route('tickets.index', ['desde' => request()->routeIs('tickets.*') ? null : '/'.ltrim(request()->path(), '/')]) }}" @if (request()->routeIs('tickets.*')) aria-current="page" @endif @if (($ticketsRespuestasNuevas ?? 0) > 0) aria-label="Ayuda, {{ $ticketsRespuestasNuevas === 1 ? '1 respuesta nueva' : $ticketsRespuestasNuevas.' respuestas nuevas' }}" @endif>
                            <x-icono nombre="help" />
                            <span class="nav-etiqueta">Ayuda</span>
                            @if (($ticketsRespuestasNuevas ?? 0) > 0)
                                <span class="aviso-ticket" aria-hidden="true">{{ $ticketsRespuestasNuevas }}</span>
                            @endif
                        </a>
                    </div>
                @endunless
            </nav>
            <div class="lateral-cuenta">
                <span class="lateral-inicial" aria-hidden="true">{{ $iniciales }}</span>
                <span class="lateral-quien">
                    <span class="persona">{{ auth()->user()->name }}</span>
                    <span class="lateral-papel">{{ $papel }}</span>
                    <a href="{{ route('mi-cuenta') }}">Mi cuenta</a>
                </span>
                <form method="post" action="{{ route('logout') }}">
                    @csrf
                    <button class="lateral-salir" type="submit" aria-label="Salir">
                        <x-icono nombre="logout" />
                    </button>
                </form>
            </div>
        </header>
        <button class="nav-fondo" data-nav-fondo type="button" tabindex="-1" aria-hidden="true" hidden></button>
    @endauth

    @auth
        @php
            $fechaCorta = now()->locale('es');
            $fechaBarra = ucfirst($fechaCorta->isoFormat('dddd')).', '.$fechaCorta->day.' '.rtrim($fechaCorta->isoFormat('MMM'), '.').' '.$fechaCorta->year;
        @endphp
        <div class="cuerpo">
            <header class="cabecera-app">
                <a class="cabecera-logo" href="{{ route(auth()->user()->paginaInicio()) }}">
                    <img class="isotipo" src="{{ asset('marca/logos/abacoqd_isotipo.svg') }}" alt="">
                    <span>
                        <span class="marca-nombre">Control horario</span>
                        <span class="marca-sub">AbacoQD</span>
                    </span>
                </a>
                <button class="cabecera-plegar" type="button" data-plegar aria-label="Plegar navegación" title="Plegar navegación" aria-controls="navegacion-principal" aria-expanded="true">
                    <x-icono nombre="list" />
                </button>
                <nav class="migas" aria-label="Ruta">
                    <a href="{{ route(auth()->user()->paginaInicio()) }}">AbacoQD</a>
                    @if (! empty($miga['url'] ?? null))
                        <span aria-hidden="true">›</span>
                        <a href="{{ $miga['url'] }}">{{ $miga['texto'] }}</a>
                    @endif
                    <span aria-hidden="true">›</span>
                    <span>{{ $titulo ?? 'Hoy' }}</span>
                </nav>
                <span class="cabecera-fecha">{{ $fechaBarra }}</span>
                <button type="button" class="tema" data-tema-toggle aria-label="Cambiar a modo oscuro">
                    <x-icono nombre="moon" class="tema-luna" />
                    <x-icono nombre="sun" class="tema-sol" />
                </button>
                <span class="cabecera-inicial" aria-hidden="true">{{ $iniciales }}</span>
            </header>
    @endauth

    <div class="pagina {{ ($acceso ?? false) ? 'pagina-acceso' : '' }} {{ ($compacta ?? false) ? 'pagina-fichar' : '' }}">
        <p class="sin-conexion" data-sin-conexion role="status" hidden>Sin conexión. Los cambios no se pueden enviar.</p>

        @unless ($compacta ?? false)
            @if (session('ok'))
                <p class="mensaje-exito" role="status">{{ session('ok') }}</p>
            @endif
            @if (session('aviso'))
                <p class="franja" role="status">{{ session('aviso') }}</p>
            @endif
            @if ($errors->any())
                <p class="franja" role="alert">{{ $errors->first() }}</p>
            @endif
        @endunless

        <main id="contenido">
            @yield('contenido')
        </main>

        @if (auth()->check() && auth()->user()->esJefa() && request()->routeIs('jefe.*'))
            <p class="nota-datos">Las consultas de registros quedan registradas.</p>
        @endif
    </div>
    @auth
        </div>
    @endauth
    <script src="{{ asset('js/reloj.js') }}?v={{ filemtime(public_path('js/reloj.js')) }}"></script>
    <script>
        (function () {
            var raiz = document.documentElement;
            var boton = document.querySelector('[data-tema-toggle]');
            if (!boton) {
                return;
            }
            function pintar() {
                var oscuro = raiz.getAttribute('data-tema') === 'oscuro';
                boton.setAttribute('aria-label', oscuro ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro');
                boton.setAttribute('aria-pressed', oscuro ? 'true' : 'false');
            }
            pintar();
            boton.addEventListener('click', function () {
                var oscuro = raiz.getAttribute('data-tema') !== 'oscuro';
                raiz.setAttribute('data-tema', oscuro ? 'oscuro' : 'claro');
                try { localStorage.setItem('abaco-tema', oscuro ? 'oscuro' : 'claro'); } catch (e) {}
                pintar();
            });
        })();
        (function () {
            var plegar = document.querySelector('[data-plegar]');
            if (!plegar) {
                return;
            }
            var lateral = document.querySelector('.lateral');
            var contenido = document.querySelector('.cuerpo');
            var cerrar = document.querySelector('[data-nav-cerrar]');
            var fondo = document.querySelector('[data-nav-fondo]');
            var movil = window.matchMedia('(max-width: 800px)');
            var abierta = false;
            function pintarNav(plegada) {
                document.body.classList.toggle('nav-plegada', plegada);
                var expandida = movil.matches ? abierta : !plegada;
                var etiqueta = movil.matches ? (abierta ? 'Cerrar menú' : 'Abrir menú') : (plegada ? 'Ampliar navegación' : 'Plegar navegación');
                document.body.classList.toggle('nav-abierta', movil.matches && abierta);
                plegar.setAttribute('aria-label', etiqueta);
                plegar.setAttribute('title', etiqueta);
                plegar.setAttribute('aria-expanded', String(expandida));
                lateral.inert = movil.matches && !abierta;
                contenido.inert = movil.matches && abierta;
                fondo.hidden = !(movil.matches && abierta);
            }
            var guardada = false;
            try { guardada = localStorage.getItem('abaco-nav') === 'plegada'; } catch (e) {}
            pintarNav(guardada);
            document.querySelectorAll('.nav-etiqueta').forEach(function (etiqueta) {
                etiqueta.closest('a').setAttribute('title', etiqueta.textContent.trim());
            });
            function cerrarMovil() {
                abierta = false;
                pintarNav(document.body.classList.contains('nav-plegada'));
                plegar.focus();
            }
            plegar.addEventListener('click', function () {
                if (movil.matches) {
                    abierta = !abierta;
                    pintarNav(document.body.classList.contains('nav-plegada'));
                    if (abierta) cerrar.focus();
                    return;
                }
                var plegada = !document.body.classList.contains('nav-plegada');
                pintarNav(plegada);
                try { localStorage.setItem('abaco-nav', plegada ? 'plegada' : 'abierta'); } catch (e) {}
            });
            cerrar.addEventListener('click', cerrarMovil);
            fondo.addEventListener('click', cerrarMovil);
            document.addEventListener('keydown', function (evento) {
                if (!movil.matches || !abierta) return;
                if (evento.key === 'Escape') { evento.preventDefault(); cerrarMovil(); return; }
                if (evento.key !== 'Tab') return;
                var focos = Array.from(lateral.querySelectorAll('a,button')).filter(function (elemento) { return !elemento.disabled && elemento.getClientRects().length; });
                var primero = focos[0], ultimo = focos[focos.length - 1];
                if (evento.shiftKey && document.activeElement === primero) { evento.preventDefault(); ultimo.focus(); }
                else if (!evento.shiftKey && document.activeElement === ultimo) { evento.preventDefault(); primero.focus(); }
            });
            movil.addEventListener('change', function () {
                abierta = false;
                pintarNav(document.body.classList.contains('nav-plegada'));
            });
        })();
    </script>
</body>
</html>
