@extends('layouts.app', ['titulo' => 'Cobertura de vacaciones', 'miga' => ['texto' => 'Gestión', 'url' => route('gestion.index')]])

@section('contenido')
    <div class="gestion-pagina">
        <header class="gestion-cabeza">
            <p class="espacio-sobre">Gestión</p>
            <h1>Cobertura de vacaciones</h1>
            <p class="gestion-intro">Al elegir una solicitud, el calendario muestra cuánta gente quedaría en la sede.</p>
        </header>

        <section class="gestion-tarjeta gestion-tarjeta-vacaciones" data-cobertura-pagina aria-labelledby="titulo-cobertura-pagina">
            <h2 id="titulo-cobertura-pagina" class="sr">Calendario y solicitudes</h2>
            @include('gestion.cobertura')

            <div class="gestion-tarjeta-cuerpo" aria-label="Solicitudes de vacaciones pendientes">
                @if ($solicitudes->isEmpty())
                    <p class="gestion-vacio">
                        <x-icono nombre="circle-check" />
                        <span>No hay solicitudes pendientes.</span>
                    </p>
                @else
                    <ul class="gestion-solicitudes">
                        @foreach ($solicitudes as $solicitud)
                            @php
                                $inicialesPersona = collect(explode(' ', $solicitud->autor->name))->map(fn ($parte) => mb_substr($parte, 0, 1))->take(2)->join('');
                                $avisoCobertura = ($cobertura['solicitudes'] ?? [])[$solicitud->id] ?? null;
                            @endphp
                            <li @class(['gestion-solicitud', 'cobertura-fila', 'cobertura-'.$avisoCobertura['nivel'] => $avisoCobertura]) @if ($avisoCobertura) data-solicitud="{{ $solicitud->id }}" data-sede="{{ $avisoCobertura['sedeId'] }}" @endif>
                                <span class="gestion-avatar" aria-hidden="true">{{ $inicialesPersona }}</span>
                                @if ($avisoCobertura)
                                    <button type="button" class="gestion-solicitud-datos cobertura-abrir" aria-expanded="false" aria-controls="cobertura-detalle-{{ $solicitud->id }}">
                                        <strong>{{ $solicitud->autor->name }}</strong>
                                        <span class="consultas-fecha">{{ $solicitud->periodoTexto() }}</span>
                                        <span class="cobertura-resumen">{{ $avisoCobertura['resumen'] }}</span>
                                    </button>
                                @else
                                    <span class="gestion-solicitud-datos">
                                        <strong>{{ $solicitud->autor->name }}</strong>
                                        <span class="consultas-fecha">{{ $solicitud->periodoTexto() }}</span>
                                    </span>
                                @endif
                                <span class="solicitud-acciones">
                                    <form method="post" action="{{ route('jefe.vacaciones.decidir', $solicitud) }}">
                                        @csrf
                                        <input type="hidden" name="decision" value="{{ \App\Models\SolicitudVacaciones::APROBADA }}">
                                        <x-boton tipo="principal" tipoHtml="submit" class="solicitud-accion solicitud-aprobar" aria-label="Aprobar solicitud de {{ $solicitud->autor->name }}" title="Aprobar">
                                            <x-icono nombre="circle-check" />
                                        </x-boton>
                                    </form>
                                    <form method="post" action="{{ route('jefe.vacaciones.decidir', $solicitud) }}">
                                        @csrf
                                        <input type="hidden" name="decision" value="{{ \App\Models\SolicitudVacaciones::RECHAZADA }}">
                                        <x-boton tipo="secundario" tipoHtml="submit" class="solicitud-accion solicitud-rechazar" aria-label="Rechazar solicitud de {{ $solicitud->autor->name }}" title="Rechazar">
                                            <x-icono nombre="triangle-alert" />
                                        </x-boton>
                                    </form>
                                </span>
                                @if ($avisoCobertura && $avisoCobertura['dias'] !== [])
                                    <div id="cobertura-detalle-{{ $solicitud->id }}" class="cobertura-detalle" hidden>
                                        <ul>
                                            @foreach ($avisoCobertura['dias'] as $diaCobertura)
                                                <li class="cobertura-{{ $diaCobertura['nivel'] }}">{{ $diaCobertura['texto'] }}</li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
            @if ($solicitudes instanceof \Illuminate\Contracts\Pagination\Paginator && $solicitudes->hasPages())
                <x-paginacion :paginas="$solicitudes" etiqueta="solicitudes" />
            @endif
        </section>
    </div>
    <script src="{{ asset('js/cobertura-vacaciones.js') }}?v={{ filemtime(public_path('js/cobertura-vacaciones.js')) }}"></script>
@endsection
