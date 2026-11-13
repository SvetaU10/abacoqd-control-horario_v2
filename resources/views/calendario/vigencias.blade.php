@if ($tramos->isNotEmpty())
    <section class="cal-vigencias" aria-label="Municipios del calendario de {{ $anio }}">
        <p class="cal-vigencias-titulo">Municipios de {{ $anio }}</p>
        <ol class="cal-vigencias-lista">
        @foreach ($tramos as $tramo)
            <li>
            <details>
                <summary><strong>{{ $tramo->importacion->municipio }}</strong><span>{{ $tramo->desde->format('d/m') }}–{{ $tramo->hasta->format('d/m') }}</span></summary>
                <p class="cal-ayuda">Del {{ $tramo->desde->format('d/m/Y') }} al {{ $tramo->hasta->format('d/m/Y') }} · {{ $tramo->importacion->provincia }} · {{ $tramo->importacion->comunidad }}.</p>
                @if ($tramo->importacion->estado === 'contexto_manual')<p class="cal-ayuda">Festivos añadidos a mano. El resto del calendario sigue pendiente de completar.</p>@endif
                @if ($tramo->importacion->fuentes)
                    <ul class="cal-fuentes-lista">@foreach ($tramo->importacion->fuentes as $fuente)<li>@if (str_starts_with($fuente['url'] ?? '', 'https://'))<a href="{{ $fuente['url'] }}" target="_blank" rel="noopener noreferrer">{{ $fuente['nombre'] }}<span class="sr"> (se abre en otra pestaña)</span></a>@else{{ $fuente['nombre'] }}@endif</li>@endforeach</ul>
                @endif
            </details>
            </li>
        @endforeach
        </ol>
    </section>
@endif
