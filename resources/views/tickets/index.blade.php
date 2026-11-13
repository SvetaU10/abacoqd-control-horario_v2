@extends('layouts.app', ['titulo' => 'Ayuda'])

@section('contenido')
    @php
        $consultaTickets = fn (?string $estado = null, ?bool $todas = null) => array_filter([
            'estado' => $estado,
            'todas_incidencias' => ($todas ?? $mostrarTodas) ? 1 : null,
            'desde' => $desde,
        ]);
    @endphp
    <div class="tickets-pagina">
        <header class="tickets-cabeza">
            <h1>Ayuda</h1>
        </header>

        <section class="tickets-panel" aria-labelledby="titulo-ayuda-tickets">
            <header class="tickets-panel-cabeza">
                <div class="tickets-panel-titulo">
                    <span class="tickets-marca" aria-hidden="true">
                        <x-icono nombre="ticket" />
                    </span>
                    <div>
                        <h2 id="titulo-ayuda-tickets">Mis tickets</h2>
                        <p>
                            @if ($todos === 0)
                                Abre un ticket y el técnico te responde aquí.
                            @elseif ($pendientes === 0)
                                {{ $todos === 1 ? '1 ticket' : $todos.' tickets' }}
                            @else
                                {{ $pendientes === 1 ? '1 sin resolver' : $pendientes.' sin resolver' }}
                                · {{ $todos === 1 ? '1 ticket' : $todos.' tickets' }}
                            @endif
                        </p>
                    </div>
                    @if ($pendientes > 0)
                        <span class="pastilla pastilla-pendiente">{{ $pendientes }}</span>
                    @endif
                    <div class="tickets-panel-acciones">
                        <form method="get" action="{{ route('tickets.index') }}" class="tickets-filtro-linea">
                            @if ($mostrarTodas)
                                <input type="hidden" name="todas_incidencias" value="1">
                            @endif
                            @if ($desde)
                                <input type="hidden" name="desde" value="{{ $desde }}">
                            @endif
                            <x-icono nombre="filter" />
                            <label class="sr" for="ayuda-ticket-estado">Filtrar por estado</label>
                            <select id="ayuda-ticket-estado" name="estado" onchange="this.form.requestSubmit()">
                                <option value="{{ \App\Models\Ticket::ABIERTO }}" @selected($filtro === \App\Models\Ticket::ABIERTO)>Abierto</option>
                                <option value="{{ \App\Models\Ticket::EN_CURSO }}" @selected($filtro === \App\Models\Ticket::EN_CURSO)>En curso</option>
                                <option value="{{ \App\Models\Ticket::RESUELTO }}" @selected($filtro === \App\Models\Ticket::RESUELTO)>Resuelto</option>
                                <option value="todos" @selected($filtro === 'todos')>Todos</option>
                            </select>
                        </form>
                        <x-boton tipo="principal" :href="route('tickets.create', ['desde' => $desde])">Abrir un ticket</x-boton>
                    </div>
                </div>
            </header>

            <div class="tickets-panel-cuerpo">
                @if ($tickets->isEmpty())
                    <p class="tickets-vacio">
                        <x-icono nombre="inbox" />
                        <span>
                            @if ($todos === 0)
                                Todavía no has abierto ningún ticket.
                            @elseif ($filtro === \App\Models\Ticket::ABIERTO)
                                No tienes tickets abiertos.
                            @elseif ($filtro === \App\Models\Ticket::EN_CURSO)
                                No tienes tickets en curso.
                            @elseif ($filtro === \App\Models\Ticket::RESUELTO)
                                No tienes tickets resueltos.
                            @else
                                Todavía no has abierto ningún ticket.
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
                                            {{ \App\Support\Tiempo::fecha($ticket->created_at) }}
                                            {{ $ticket->created_at->format('H:i') }}
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
                        :href="route('tickets.index', $consultaTickets($filtro === 'todos' ? null : $filtro, ! $mostrarTodas))"
                    >
                        {{ $mostrarTodas ? 'Ver menos' : 'Ver todas las incidencias' }}
                    </x-boton>
                </footer>
            @endif
        </section>
    </div>
@endsection
