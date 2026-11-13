@php
    $resumen = $vacaciones;
    $estados = ['disfrutado' => 'Disfrutado', 'en-curso' => 'En curso', 'por-disfrutar' => 'Por disfrutar'];
    $textoDias = fn (int $numero): string => $numero === 1 ? '1 día' : $numero.' días';
    $textoPeriodo = fn ($desde, $hasta): string => $desde->isSameDay($hasta)
        ? 'El '.$desde->format('d/m/Y')
        : 'Del '.$desde->format('d/m/Y').' al '.$hasta->format('d/m/Y');
    $puedePedir = $vacacionesEnPlazo && ($resumen['sinAsignar'] ?? 0) > 0;
    $fuera = $resumen['fueraDePlantilla'];
    $hayListas = $resumen['periodos'] !== [] || $resumen['solicitadas'] !== [] || $resumen['rechazadas'] !== [];
    $verbo = match (true) {
        $anio < now()->year => 'No estabas',
        $anio > now()->year => 'No estarás',
        default => 'No estás',
    };
@endphp
<section @class(['calendario-panel', 'vacaciones-anio', 'vacaciones-anio-ancho' => $ancho ?? false]) aria-labelledby="titulo-vacaciones-anio" data-vacaciones-panel>
    <header class="vacaciones-anio-cabecera">
        <h2 id="titulo-vacaciones-anio">Vacaciones de {{ $anio }}</h2>
        @if ($puedePedir)
            {{-- Solo funciona con JavaScript: reloj.js lo muestra al arrancar. --}}
            <button class="boton boton-principal vacaciones-anio-pedir" type="button" data-vacaciones-pedir hidden>Pedir vacaciones</button>
        @endif
    </header>

    @if ($fuera)
        <p class="vacaciones-anio-nota">{{ $verbo }} en plantilla en {{ $anio }}.</p>
    @else
        <dl class="vacaciones-anio-cifras">
            <div>
                <dt>Te corresponden</dt>
                <dd>
                    @if ($resumen['corresponden'] === null)
                        <span class="vacaciones-anio-pendiente">Pendiente de confirmar por el responsable</span>
                    @else
                        {{ $resumen['corresponden'] }}
                    @endif
                </dd>
            </div>
            <div>
                <dt>Disfrutados</dt>
                <dd>{{ $resumen['disfrutados'] }}</dd>
            </div>
            <div>
                <dt>Aprobados por disfrutar</dt>
                <dd>{{ $resumen['porDisfrutar'] }}</dd>
            </div>
            <div>
                <dt>Pedidos en espera</dt>
                <dd>{{ $resumen['enEspera'] }}</dd>
            </div>
            <div>
                <dt>Sin asignar</dt>
                <dd>
                    @if ($resumen['sinAsignar'] === null)
                        <span class="vacaciones-anio-pendiente">Sin confirmar</span>
                    @else
                        <span class="vacaciones-total">{{ $resumen['sinAsignar'] }}</span>
                    @endif
                </dd>
            </div>
        </dl>
        <p class="secundario vacaciones-anio-ayuda">Días laborables: los fines de semana y los festivos no cuentan.</p>

        @if ($resumen['corresponden'] === null)
            <p class="vacaciones-anio-nota">Saldo sin confirmar.@if ($vacacionesEnPlazo) Hasta que el responsable lo confirme no se pueden pedir vacaciones de {{ $anio }}.@endif</p>
        @elseif ($resumen['exceso'] > 0)
            <p class="vacaciones-anio-nota">Entre aprobados y pedidos hay {{ $textoDias($resumen['exceso']) }} más de los que te corresponden. Habla con el responsable.</p>
        @endif

        @if (! $vacacionesEnPlazo && $anio > now()->year)
            <p class="secundario vacaciones-anio-ayuda">Solo se pueden pedir fechas de los próximos dos meses.</p>
        @elseif ($vacacionesEnPlazo && $resumen['sinAsignar'] === 0 && $resumen['exceso'] === 0)
            <p class="secundario vacaciones-anio-ayuda">No te quedan días sin asignar en {{ $anio }}.</p>
        @endif
    @endif

    @if (! $fuera || $hayListas)
        <div class="vacaciones-anio-listas">
            <div>
                <h3>Aprobadas</h3>
                @if ($resumen['periodos'] === [])
                    <p class="secundario">Ninguna en {{ $anio }}.</p>
                @else
                    <ul class="vacaciones-anio-lista">
                        @foreach ($resumen['periodos'] as $periodo)
                            <li class="vacaciones-anio-{{ $periodo['estado'] }}">
                                <span>{{ $textoPeriodo($periodo['desde'], $periodo['hasta']) }}</span>
                                <small>{{ $textoDias($periodo['dias']) }} · {{ $estados[$periodo['estado']] }}</small>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div>
                <h3>En espera</h3>
                @if ($resumen['solicitadas'] === [])
                    <p class="secundario">Ninguna en {{ $anio }}.</p>
                @else
                    <ul class="vacaciones-anio-lista">
                        @foreach ($resumen['solicitadas'] as $pedida)
                            <li class="vacaciones-anio-espera">
                                <span>{{ $pedida['solicitud']->periodoTexto() }}</span>
                                <small>{{ $textoDias($pedida['dias']) }}@if ($pedida['solicitud']->starts_on->year !== $pedida['solicitud']->ends_on->year) en {{ $anio }}@endif · Pendiente de decidir</small>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div>
                <h3>Rechazadas</h3>
                @if ($resumen['rechazadas'] === [])
                    <p class="secundario">Ninguna en {{ $anio }}.</p>
                @else
                    <ul class="vacaciones-anio-lista">
                        @foreach ($resumen['rechazadas'] as $rechazada)
                            <li class="vacaciones-anio-rechazada">
                                <span>{{ $rechazada->periodoTexto() }}</span>
                                @if ($rechazada->decidida_at)
                                    <small>Decidida el {{ $rechazada->decidida_at->format('d/m/Y') }}</small>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    @endif
</section>
