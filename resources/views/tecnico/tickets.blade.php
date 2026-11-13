@extends('layouts.app', ['titulo' => 'Tickets'])

@section('contenido')
    @php
        $consultaTickets = fn (?string $estado = null, bool $conTrabajador = true, ?bool $todas = null) => array_filter([
            'estado' => $estado,
            'trabajador' => $conTrabajador ? $trabajador?->id : null,
            'todas_incidencias' => ($todas ?? $mostrarTodas) ? 1 : null,
        ]);
    @endphp
    <div class="tickets-pagina">
        <header class="tickets-cabeza">
            <p class="volver-linea">
                <a class="volver" href="{{ route('tecnico.index') }}">Volver a Sistema</a>
            </p>
            <h1>Tickets</h1>
        </header>

        <section class="tickets-panel" aria-labelledby="titulo-tickets">
            <header class="tickets-panel-cabeza">
                <div class="tickets-panel-titulo">
                    <span class="tickets-marca" aria-hidden="true">
                        <x-icono nombre="ticket" />
                    </span>
                    <div>
                        <h2 id="titulo-tickets">{{ $trabajador?->name ?? 'Equipo' }}</h2>
                        <p>
                            @if ($trabajador)
                                {{ $pendientes === 1 ? '1 sin resolver' : $pendientes.' sin resolver' }}.
                            @else
                                {{ $pendientes === 1 ? '1 sin resolver' : $pendientes.' sin resolver' }} · {{ $todos === 1 ? '1 ticket' : $todos.' tickets' }}
                            @endif
                        </p>
                    </div>
                    @if ($pendientes > 0)
                        <span class="pastilla pastilla-pendiente">{{ $pendientes }}</span>
                    @endif

                    <form method="get" action="{{ route('tecnico.tickets') }}" class="tickets-filtro-linea">
                        @if ($mostrarTodas)
                            <input type="hidden" name="todas_incidencias" value="1">
                        @endif
                        <x-icono nombre="filter" />
                        <label class="sr" for="ticket-trabajador">Filtrar por trabajador</label>
                        <select id="ticket-trabajador" name="trabajador" onchange="this.form.requestSubmit()">
                            <option value="">Todos los trabajadores</option>
                            @foreach ($trabajadores as $persona)
                                <option value="{{ $persona->id }}" @selected($trabajador?->id === $persona->id)>{{ $persona->name }}</option>
                            @endforeach
                        </select>
                        <label class="sr" for="ticket-estado">Filtrar por estado</label>
                        <select id="ticket-estado" name="estado" onchange="this.form.requestSubmit()">
                            <option value="{{ \App\Models\Ticket::ABIERTO }}" @selected($filtro === \App\Models\Ticket::ABIERTO)>Abierto</option>
                            <option value="{{ \App\Models\Ticket::EN_CURSO }}" @selected($filtro === \App\Models\Ticket::EN_CURSO)>En curso</option>
                            <option value="{{ \App\Models\Ticket::RESUELTO }}" @selected($filtro === \App\Models\Ticket::RESUELTO)>Resuelto</option>
                            <option value="todos" @selected($filtro === 'todos')>Todos</option>
                        </select>
                    </form>
                </div>

                <div class="tickets-barra">
                    <nav class="tickets-filtros" aria-label="Filtrar tickets">
                        <a class="tickets-filtro" href="{{ route('tecnico.tickets', $consultaTickets()) }}" @if ($filtro === \App\Models\Ticket::ABIERTO) aria-current="page" @endif>
                            Abierto
                            <span class="tickets-filtro-cuenta">{{ $abiertos }}</span>
                        </a>
                        <a class="tickets-filtro" href="{{ route('tecnico.tickets', $consultaTickets(\App\Models\Ticket::EN_CURSO)) }}" @if ($filtro === \App\Models\Ticket::EN_CURSO) aria-current="page" @endif>
                            En curso
                            <span class="tickets-filtro-cuenta">{{ $enCurso }}</span>
                        </a>
                        <a class="tickets-filtro" href="{{ route('tecnico.tickets', $consultaTickets(\App\Models\Ticket::RESUELTO)) }}" @if ($filtro === \App\Models\Ticket::RESUELTO) aria-current="page" @endif>
                            Resuelto
                            <span class="tickets-filtro-cuenta">{{ $resueltos }}</span>
                        </a>
                        <a class="tickets-filtro" href="{{ route('tecnico.tickets', $consultaTickets('todos')) }}" @if ($filtro === 'todos') aria-current="page" @endif>
                            Todos
                            <span class="tickets-filtro-cuenta">{{ $todos }}</span>
                        </a>
                    </nav>
                </div>
            </header>

            <div class="tickets-panel-cuerpo">
                @if ($tickets->isEmpty())
                    <p class="tickets-vacio">
                        <x-icono nombre="inbox" />
                        <span>
                            @if ($trabajador && $filtro === \App\Models\Ticket::ABIERTO)
                                {{ $trabajador->name }} no tiene tickets abiertos.
                            @elseif ($trabajador && $filtro === \App\Models\Ticket::EN_CURSO)
                                {{ $trabajador->name }} no tiene tickets en curso.
                            @elseif ($trabajador && $filtro === \App\Models\Ticket::RESUELTO)
                                {{ $trabajador->name }} no tiene tickets resueltos.
                            @elseif ($trabajador)
                                {{ $trabajador->name }} no tiene tickets.
                            @elseif ($filtro === \App\Models\Ticket::ABIERTO)
                                No hay tickets abiertos.
                            @elseif ($filtro === \App\Models\Ticket::EN_CURSO)
                                No hay tickets en curso.
                            @elseif ($filtro === \App\Models\Ticket::RESUELTO)
                                Todavía no hay tickets resueltos.
                            @else
                                Todavía no hay tickets.
                            @endif
                        </span>
                    </p>
                @else
                    <ul class="tickets-lista">
                        @foreach ($tickets as $ticket)
                            @php
                                $iniciales = mb_strtoupper(collect(explode(' ', $ticket->autor->name ?? ''))
                                    ->filter()
                                    ->map(fn (string $parte) => mb_substr($parte, 0, 1))
                                    ->take(2)
                                    ->join('')) ?: '?';
                            @endphp
                            <li>
                                <a class="ticket-fila" href="{{ route('tickets.show', $ticket) }}">
                                    <span class="ticket-avatar" aria-hidden="true">{{ $iniciales }}</span>
                                    <span class="ticket-cuerpo">
                                        <strong>{{ $ticket->asunto }}</strong>
                                        <span class="ticket-meta">
                                            {{ $ticket->autor->name ?? 'Persona' }}
                                            · {{ \App\Support\Tiempo::fecha($ticket->created_at) }} {{ $ticket->created_at->format('H:i') }}
                                        </span>
                                    </span>
                                    <x-chip :tipo="$ticket->estado">{{ $ticket->etiquetaDeEstado() }}</x-chip>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @if ($hayMas)
                <footer class="tickets-panel-pie">
                    @if ($mostrarTodas)
                        @include('tickets.paginacion', ['paginas' => $tickets])
                    @endif
                    <x-boton
                        tipo="secundario"
                        :href="route('tecnico.tickets', $consultaTickets($filtro === \App\Models\Ticket::ABIERTO ? null : $filtro, true, ! $mostrarTodas))"
                    >
                        {{ $mostrarTodas ? 'Ver menos' : 'Ver todas las incidencias' }}
                    </x-boton>
                </footer>
            @endif
        </section>
    </div>
@endsection
