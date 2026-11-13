@extends('layouts.app', ['ancha' => true, 'titulo' => $verBajas ? 'Bajas' : 'Personas'])

@section('contenido')
    <div class="personas-pagina">
        <header class="personas-cabeza">
            <div>
                <h1>{{ $verBajas ? 'Bajas' : 'Personas' }}</h1>
                <p class="secundario">
                    {{ $verBajas
                        ? 'Personas dadas de baja. Su registro se conserva y se pueden reincorporar desde su ficha.'
                        : 'Gestiona las cuentas, horarios y accesos del equipo.' }}
                </p>
            </div>
            <x-boton tipo="principal" :href="route('equipo.create')">
                <x-icono nombre="user-plus" />
                Dar de alta
            </x-boton>
        </header>

        <div class="personas-resumen" aria-label="Resumen del equipo">
            <span>
                <strong>{{ $personas->count() }}</strong>
                @if ($verBajas)
                    {{ $personas->count() === 1 ? 'baja' : 'bajas' }}
                @else
                    {{ $personas->count() === 1 ? 'persona' : 'personas' }}
                @endif
            </span>
        </div>

        <section class="personas-grid" aria-label="{{ $verBajas ? 'Personas dadas de baja' : 'Personas del equipo' }}">
            @forelse ($personas as $persona)
                @php
                    $partes = preg_split('/\s+/', trim($persona->name)) ?: [];
                    $iniciales = mb_strtoupper(mb_substr($partes[0] ?? '', 0, 1).mb_substr($partes[1] ?? '', 0, 1));
                    $papel = $persona->esJefa() ? 'Responsable' : ($persona->esTecnico() ? 'Técnico' : 'Trabajador');
                    $horario = $persona->horarios
                        ->filter(fn ($horario) => $horario->effective_from?->lte(now()))
                        ->sortByDesc('effective_from')
                        ->first();
                @endphp
                <article class="persona-card">
                    <div class="persona-card-cabeza">
                        <span class="persona-card-avatar" aria-hidden="true">{{ $iniciales }}</span>
                        <span class="persona-card-nombre">
                            <strong>{{ $persona->name }}</strong>
                            <small>{{ $persona->sede?->name ?? $persona->municipality }}</small>
                        </span>
                        <span class="persona-card-lado">
                            <span class="persona-card-rol">{{ $verBajas ? 'De baja' : $papel }}</span>
                            <a class="persona-card-editar" href="{{ route('equipo.edit', $persona) }}" aria-label="Editar alta de {{ $persona->name }}" title="Editar alta">
                                <x-icono nombre="pencil" />
                            </a>
                        </span>
                    </div>
                    @if (! $verBajas && $persona->tieneBajaProgramadaEl($hoy))
                        <p class="persona-card-aviso">Baja prevista: último día el {{ $persona->baja_on->format('d/m/Y') }}.</p>
                    @endif
                    <details class="persona-card-ficha">
                        <summary class="persona-card-enlace">
                            Ver ficha
                            <span aria-hidden="true">›</span>
                        </summary>
                        <div class="persona-card-detalles">
                            <div class="persona-card-detalle">
                                <span>Fecha de inicio</span>
                                <strong>{{ $persona->starts_on?->format('d/m/Y') ?? 'Sin indicar' }}</strong>
                            </div>
                            @if ($verBajas)
                                <div class="persona-card-detalle">
                                    <span>Último día en la empresa</span>
                                    <strong>{{ $persona->baja_on?->format('d/m/Y') ?? 'Sin fecha registrada' }}</strong>
                                </div>
                            @endif
                            <div class="persona-card-detalle">
                                <span>Tipo de jornada</span>
                                <strong>{{ $horario?->tipoJornada()?->etiqueta() ?? 'Sin asignar' }}</strong>
                            </div>
                            <div class="persona-card-detalle">
                                <span>Tipo de contrato</span>
                                <strong>{{ $persona->ends_on ? 'Temporal' : 'Indefinido' }}</strong>
                            </div>
                            <div class="persona-card-detalle">
                                <span>Horario de trabajo</span>
                                <strong>{{ $horario?->texto() ?? 'Sin asignar' }}</strong>
                            </div>
                            @if ($verBajas)
                                <a class="boton boton-secundario" href="{{ route('equipo.show', $persona) }}">Ver ficha y registro</a>
                            @else
                                <a class="boton boton-secundario" href="{{ route('equipo.show', $persona) }}">Gestionar horario y vacaciones</a>
                            @endif
                        </div>
                    </details>
                    @if ($verBajas)
                        <div class="persona-card-acciones">
                            <x-boton tipo="principal" class="boton-con-icono" :href="route('equipo.show', ['user' => $persona, 'reincorporar' => 1])">
                                <x-icono nombre="user-check" />
                                {{ $persona->bajaAnulableEl($hoy) ? 'Anular baja' : 'Reincorporar' }}
                            </x-boton>
                        </div>
                    @endif
                </article>
            @empty
                <p class="secundario">{{ $verBajas ? 'No hay personas dadas de baja.' : 'Todavía no hay personas en el equipo.' }}</p>
            @endforelse
        </section>

        <div class="personas-pie">
            @if (! $verBajas && ($mostrarTodas || $totalPersonas > $personas->count()))
                <x-boton
                    tipo="principal"
                    :href="route('equipo.index', $mostrarTodas ? [] : ['todas_personas' => 1])"
                    class="personas-todos"
                >
                    {{ $mostrarTodas ? 'Volver' : 'Todos los trabajadores' }}
                </x-boton>
            @endif
            @if ($verBajas)
                <x-boton :href="route('equipo.index')" class="personas-bajas boton-con-icono">
                    <x-icono nombre="arrow-left" />
                    Volver al equipo
                </x-boton>
            @else
                <x-boton class="personas-bajas boton-con-icono" :href="route('equipo.index', ['bajas' => 1])">
                    <x-icono nombre="user-minus" />
                    Bajas ({{ $totalBajas }})
                </x-boton>
            @endif
        </div>
    </div>
@endsection
