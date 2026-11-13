<section class="mes-caja mes-unico calendario-mes-card">
<div class="mes-nav">
    @if ($mesAnterior)
        <a class="mes-flecha" href="{{ route('calendario', $mesAnterior) }}" data-calendario-ir aria-label="Mes anterior">‹</a>
    @else
        <span class="mes-flecha" aria-hidden="true"></span>
    @endif
    <h2>{{ $mes['nombre'] }} {{ $anio }}</h2>
    @if ($mesSiguiente)
        <a class="mes-flecha" href="{{ route('calendario', $mesSiguiente) }}" data-calendario-ir aria-label="Mes siguiente">›</a>
    @else
        <span class="mes-flecha" aria-hidden="true"></span>
    @endif
</div>

<div class="rejilla-mes">
        @foreach (['L', 'M', 'X', 'J', 'V', 'S', 'D'] as $letra)
            <span class="cabecera-dia">{{ $letra }}</span>
        @endforeach
        @foreach ($mes['dias'] as $dia)
            @if ($dia === null)
                <span class="casilla casilla-vacia"></span>
            @else
                @php
                    $festivo = $dia['festivo'];
                    $clases = 'casilla';
                    if ($dia['hoy']) {
                        $clases .= ' casilla-hoy';
                    }
                    if ($festivo) {
                        $clases .= ' casilla-'.$festivo->ambito;
                    } elseif ($dia['finde']) {
                        $clases .= ' casilla-finde';
                    }
                    if ($dia['ausencia'] && $dia['ausencia']->tipo === 'vacaciones') {
                        if ($dia['cuenta']) {
                            $clases .= ' casilla-vacaciones';
                        }
                    } elseif ($dia['ausencia']) {
                        $clases .= ' casilla-'.$dia['ausencia']->tipo;
                    } elseif ($dia['pendiente']) {
                        $clases .= ' casilla-pendiente';
                    }
                    $titulo = collect([
                        $dia['hoy'] ? 'Hoy.' : null,
                        $festivo ? $festivo->name.' · '.$festivo->etiqueta() : null,
                        $dia['ausencia']?->tipoTexto(),
                        $dia['pendiente'] ? 'Vacaciones pendientes' : null,
                    ])->filter()->implode(' ');
                    $relleno = $dia['ausencia']?->tipo === 'vacaciones'
                        ? 'vacaciones' : ($festivo?->ambito ?? ($dia['pendiente'] ? 'pendiente' : ''));
                @endphp
                <span class="{{ $clases }}" data-fecha="{{ $dia['fecha'] }}" data-cuenta="{{ $dia['cuenta'] ? '1' : '0' }}" data-ambito="{{ $festivo?->ambito }}" data-relleno="{{ $relleno }}" @if ($titulo !== '') title="{{ $titulo }}" @endif>
                    <time class="cal-dia-numero" datetime="{{ $dia['fecha'] }}">{{ $dia['numero'] }}</time>
                    @if ($festivo)
                        <span class="casilla-etiqueta casilla-etiqueta-festivo cal-dia-nombre">{{ $festivo->name }}</span>
                        <span class="sr">{{ $festivo->etiqueta() }}</span>
                    @endif
                    @if ($dia['ausencia'])
                        <span class="casilla-etiqueta casilla-etiqueta-{{ $dia['ausencia']->tipo }} cal-dia-nombre">{{ $dia['ausencia']->tipoTexto() }}</span>
                    @elseif ($dia['pendiente'])
                        <span class="casilla-etiqueta casilla-etiqueta-vacaciones-pendiente cal-dia-nombre">Vacaciones pendientes</span>
                    @endif
                    @if ($dia['hoy'])
                        <small>Hoy</small>
                    @endif
                </span>
            @endif
        @endforeach
    </div>
</section>
