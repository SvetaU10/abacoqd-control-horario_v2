@if ($cobertura && $cobertura['sedes'] !== [])
    @php
        $sedeActiva = collect($cobertura['sedes'])->firstWhere('id', $cobertura['sedeInicial']) ?? $cobertura['sedes'][0];
    @endphp
    <section class="cobertura" aria-labelledby="titulo-cobertura">
        <div class="cobertura-barra">
            <h3 id="titulo-cobertura">Cobertura</h3>
            @if (count($cobertura['sedes']) > 1)
                <label class="cobertura-sede">
                    <span class="sr">Sede</span>
                    <select data-cobertura-sede>
                        @foreach ($cobertura['sedes'] as $sede)
                            <option value="{{ $sede['id'] }}" data-minimo="{{ $sede['minimo'] }}" @selected($sede['id'] === $sedeActiva['id'])>{{ $sede['nombre'] }}</option>
                        @endforeach
                    </select>
                </label>
            @else
                <p class="cobertura-sede-nombre">{{ $sedeActiva['nombre'] }}</p>
            @endif
            <form method="post" action="{{ route('equipo.centros.minimo', $sedeActiva['id']) }}" class="cobertura-minimo" data-cobertura-minimo>
                @csrf
                @method('PATCH')
                <label>
                    Mínimo
                    <input type="number" name="minimo_presencia" min="1" max="99" value="{{ $sedeActiva['minimo'] }}" required inputmode="numeric" data-cobertura-minimo-valor aria-describedby="ayuda-cobertura-minimo">
                </label>
            </form>
        </div>
        <p id="ayuda-cobertura-minimo" class="cobertura-ayuda">Personas que tienen que quedar trabajando. El color cuenta las vacaciones ya aprobadas.</p>
        @foreach ($cobertura['sedes'] as $sede)
            <div class="cobertura-tira" data-cobertura-tira="{{ $sede['id'] }}" data-minimo="{{ $sede['minimo'] }}" @if ($sede['id'] !== $sedeActiva['id']) hidden @endif tabindex="0">
                @foreach ($sede['semanas'] as $semana)
                    <div class="cobertura-semana">
                        <span class="cobertura-mes">{{ $semana['muestraMes'] ? $semana['mes'] : '' }}</span>
                        <div class="cobertura-dias">
                            @foreach ($semana['dias'] as $dia)
                                <span
                                    class="cobertura-dia cobertura-{{ $dia['nivel'] }}@if ($dia['pendientes'] !== []) tiene-pendiente @endif"
                                    data-fecha="{{ $dia['fecha'] }}"
                                    data-corta="{{ $dia['corta'] }}"
                                    data-nivel="{{ $dia['nivel'] }}"
                                    data-plantilla="{{ $dia['plantilla'] }}"
                                    data-fuera="{{ $dia['fuera'] }}"
                                    data-pendientes="{{ implode(',', $dia['pendientes']) }}"
                                    data-titulo="{{ $dia['titulo'] }}"
                                    title="{{ $dia['titulo'] }}"
                                >
                                    <span class="cobertura-letra" aria-hidden="true">{{ $dia['letra'] }}</span>
                                    <span class="cobertura-numero" aria-hidden="true">{{ $dia['numero'] }}</span>
                                    <span class="sr" data-cobertura-texto>{{ $dia['titulo'] }}</span>
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endforeach
        <p class="cobertura-leyenda">
            <span class="cobertura-muestra cobertura-ok"></span> Cabe
            <span class="cobertura-muestra cobertura-justo"></span> Justo en el mínimo
            <span class="cobertura-muestra cobertura-corto"></span> Falta gente
            <span class="cobertura-muestra tiene-pendiente"></span> Hay otra solicitud
        </p>
    </section>
@endif
