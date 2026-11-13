@extends('layouts.app', ['titulo' => $ticket->asunto])

@section('contenido')
    @php
        $inicialesDe = function (?string $nombre): string {
            return mb_strtoupper(collect(explode(' ', $nombre ?? ''))
                ->filter()
                ->map(fn (string $parte) => mb_substr($parte, 0, 1))
                ->take(2)
                ->join('')) ?: '?';
        };
    @endphp
    <div class="tickets-pagina ticket-ficha">
        <header class="tickets-cabeza">
            <p class="volver-linea">
                <a class="volver" href="{{ $esTecnico ? route('tecnico.tickets') : route('tickets.index') }}">
                    {{ $esTecnico ? 'Volver a los tickets' : 'Volver a Ayuda' }}
                </a>
            </p>
            <h1>{{ $ticket->asunto }}</h1>
        </header>

        <section class="tickets-panel" aria-labelledby="titulo-ticket">
            <header class="tickets-panel-cabeza">
                <div class="tickets-panel-titulo">
                    <span class="tickets-marca" aria-hidden="true">
                        <x-icono nombre="ticket" />
                    </span>
                    <div>
                        <h2 id="titulo-ticket">{{ $ticket->autor->name }}</h2>
                        <p>
                            {{ \App\Support\Tiempo::fecha($ticket->created_at) }}
                            · {{ $ticket->created_at->format('H:i') }}
                            @if ($ticket->pagina)
                                · {{ $ticket->pagina }}
                            @endif
                        </p>
                    </div>
                    <x-chip :tipo="$ticket->estado">{{ $ticket->etiquetaDeEstado() }}</x-chip>
                    @if ($esTecnico)
                        <form method="post" action="{{ route('tecnico.tickets.estado', $ticket) }}" class="tickets-filtro-linea">
                            @csrf
                            <label class="sr" for="estado">Cambiar el estado</label>
                            <select id="estado" name="estado" required onchange="this.form.requestSubmit()">
                                @foreach (\App\Models\Ticket::ESTADOS as $valor => $etiqueta)
                                    <option value="{{ $valor }}" @selected($ticket->estado === $valor)>{{ $etiqueta }}</option>
                                @endforeach
                            </select>
                            <noscript>
                                <button class="boton boton-secundario" type="submit">Guardar estado</button>
                            </noscript>
                        </form>
                    @endif
                </div>
            </header>

            <div class="tickets-panel-cuerpo">
                <article class="ticket-nota ticket-nota-origen">
                    <span class="ticket-avatar" aria-hidden="true">{{ $inicialesDe($ticket->autor->name) }}</span>
                    <div>
                        <strong>{{ $ticket->autor->name }}</strong>
                        <span class="ticket-meta">Abrió el ticket</span>
                        <p class="ticket-nota-texto">{!! nl2br(e($ticket->descripcion)) !!}</p>
                    </div>
                </article>

                <h3>Conversación</h3>
                @if ($ticket->mensajes->isEmpty())
                    <p class="tickets-vacio">
                        <x-icono nombre="message" />
                        <span>Todavía nadie ha contestado.</span>
                    </p>
                @else
                    <ul class="ticket-hilo">
                        @foreach ($ticket->mensajes as $mensaje)
                            <li class="ticket-nota">
                                <span class="ticket-avatar" aria-hidden="true">{{ $inicialesDe($mensaje->autor->name) }}</span>
                                <div>
                                    <strong>{{ $mensaje->autor->name }}</strong>
                                    <span class="ticket-meta">
                                        @if ($mensaje->autor->esTecnico())
                                            técnico ·
                                        @endif
                                        {{ \App\Support\Tiempo::fecha($mensaje->created_at) }}
                                        {{ $mensaje->created_at->format('H:i') }}
                                    </span>
                                    <p class="ticket-nota-texto">{!! nl2br(e($mensaje->cuerpo)) !!}</p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <form method="post" action="{{ route('tickets.mensaje', $ticket) }}" class="ticket-escribir">
                @csrf
                <label for="cuerpo">{{ $ticket->estaResuelto() ? 'Si el problema sigue, escribe aquí. Se reabrirá.' : 'Escribe un mensaje' }}</label>
                <textarea id="cuerpo" name="cuerpo" maxlength="2000" rows="3" required placeholder="Escribe un mensaje">{{ old('cuerpo') }}</textarea>
                <button class="boton boton-principal" type="submit">Enviar mensaje</button>
            </form>
        </section>
    </div>
@endsection
