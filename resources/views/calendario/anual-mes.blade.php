<a class="calendario-anual-enlace" href="{{ route('calendario', ['anio' => $anio, 'mes' => $mesAnual['numero']]) }}" data-calendario-ir>
    <section class="calendario-anual-mes" aria-labelledby="titulo-mes-{{ $mesAnual['nombre'] }}">
        <h2 id="titulo-mes-{{ $mesAnual['nombre'] }}">{{ ucfirst($mesAnual['nombre']) }}</h2>
        <div class="calendario-anual-rejilla">
        @foreach (['L', 'M', 'X', 'J', 'V', 'S', 'D'] as $letra)
            <span class="calendario-anual-cabecera">{{ $letra }}</span>
        @endforeach

        @foreach ($mesAnual['dias'] as $dia)
            @if ($dia === null)
                <span class="casilla calendario-anual-dia calendario-anual-vacio"></span>
            @else
                @php
                    $festivo = $dia['festivo'];
                    $clases = 'casilla calendario-anual-dia';
                    if ($dia['hoy']) {
                        $clases .= ' calendario-anual-hoy';
                    }
                    if ($festivo) {
                        $clases .= ' calendario-anual-'.$festivo->ambito;
                    } elseif ($dia['finde']) {
                        $clases .= ' calendario-anual-finde';
                    }
                    if ($dia['ausencia'] && $dia['ausencia']->tipo === 'vacaciones' && $dia['cuenta']) {
                        $clases .= ' calendario-anual-vacaciones';
                    } elseif ($dia['ausencia']) {
                        $clases .= ' calendario-anual-'.$dia['ausencia']->tipo;
                    } elseif ($dia['pendiente']) {
                        $clases .= ' calendario-anual-pendiente';
                    }
                    $descripcion = $dia['fecha'].($festivo ? ' · '.$festivo->name.' · '.$festivo->etiqueta() : '').($dia['ausencia'] ? ' · '.$dia['ausencia']->tipoTexto() : '').($dia['pendiente'] ? ' · Vacaciones pendientes' : '');
                    $relleno = $dia['ausencia']?->tipo === 'vacaciones'
                        ? 'vacaciones' : ($festivo?->ambito ?? ($dia['pendiente'] ? 'pendiente' : ''));
                @endphp
                <span class="{{ $clases }}" data-fecha="{{ $dia['fecha'] }}" data-cuenta="{{ $dia['cuenta'] ? '1' : '0' }}" data-ambito="{{ $festivo?->ambito }}" data-relleno="{{ $relleno }}" title="{{ $descripcion }}">
                    <time datetime="{{ $dia['fecha'] }}">{{ $dia['numero'] }}</time>
                    @if ($festivo)<span class="sr">{{ $festivo->name }} · {{ $festivo->etiqueta() }}</span>@endif
                    @if ($dia['ausencia'] && $dia['ausencia']->tipo === 'vacaciones')
                        <span class="sr">Vacaciones</span>
                    @elseif ($dia['pendiente'])
                        <span class="sr">Vacaciones pendientes</span>
                    @endif
                </span>
            @endif
        @endforeach
        </div>
        @php
            $festivosMes = collect($mesAnual['dias'])->filter(fn (?array $dia): bool => $dia !== null && $dia['festivo'] !== null);
            $vacacionesMes = collect($mesAnual['dias'])->filter(fn (?array $dia): bool => $dia !== null && $dia['ausencia']?->tipo === 'vacaciones');
            $pendientesMes = collect($mesAnual['dias'])->filter(fn (?array $dia): bool => $dia !== null && $dia['pendiente']);
        @endphp
        @if ($festivosMes->isNotEmpty())
            <ul class="cal-anual-eventos" aria-label="Festivos de {{ $mesAnual['nombre'] }}">
                @foreach ($festivosMes as $dia)
                    <li><time datetime="{{ $dia['fecha'] }}">{{ $dia['numero'] }}</time><div><span>{{ $dia['festivo']->name }}</span><x-ambito-festivo :ambito="$dia['festivo']->ambito" class="cal-anual-tipo" /></div></li>
                @endforeach
            </ul>
        @endif
        @if ($vacacionesMes->isNotEmpty())<p class="cal-anual-ausencias">Vacaciones: {{ $vacacionesMes->pluck('numero')->implode(', ') }}.</p>@endif
        @if ($pendientesMes->isNotEmpty())<p class="cal-anual-ausencias">Vacaciones pendientes: {{ $pendientesMes->pluck('numero')->implode(', ') }}.</p>@endif
    </section>
</a>
