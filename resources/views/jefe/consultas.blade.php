@extends('layouts.app', ['titulo' => 'Consultas'])

@section('contenido')
    <div class="consultas-pagina">
        <header class="consultas-cabeza">
            <div>
                <p class="espacio-sobre">Equipo</p>
                <h1>Consultas a registros</h1>
                <span class="sr">Registros consultados</span>
                <p class="secundario">Se conserva quién entró, cuándo y por qué.</p>
            </div>
        </header>

        <details class="registro-filtrar" @if ($filtrosConsultas['persona'] !== '' || $filtrosConsultas['desde'] !== '' || $filtrosConsultas['hasta'] !== '' || $filtrosConsultas['mes'] !== '') open @endif>
            <summary>
                <x-icono nombre="filter" />
                Filtrar consultas
            </summary>
            <form method="get" action="{{ route('jefe.consultas') }}" class="registro-filtros">
                <div>
                    <label for="consulta-persona">Trabajador</label>
                    <select id="consulta-persona" name="persona">
                        <option value="">Todos los trabajadores</option>
                        @foreach ($trabajadores as $trabajador)
                            <option value="{{ $trabajador->name }}" @selected($filtrosConsultas['persona'] === $trabajador->name)>{{ $trabajador->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="consulta-desde">Desde</label>
                    <x-fecha id="consulta-desde" name="desde" :value="$filtrosConsultas['desde']" />
                </div>
                <div>
                    <label for="consulta-hasta">Hasta</label>
                    <x-fecha id="consulta-hasta" name="hasta" :value="$filtrosConsultas['hasta']" />
                </div>
                <div>
                    <label for="consulta-mes">Mes</label>
                    <select id="consulta-mes" name="mes">
                        <option value="">Todos los meses</option>
                        @foreach ($mesesConsultas as $valor => $texto)
                            <option value="{{ $valor }}" @selected($filtrosConsultas['mes'] === $valor)>{{ ucfirst($texto) }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="boton boton-secundario" type="submit">Ver</button>
                @if ($filtrosConsultas['persona'] !== '' || $filtrosConsultas['desde'] !== '' || $filtrosConsultas['hasta'] !== '' || $filtrosConsultas['mes'] !== '')
                    <x-boton tipo="secundario" :href="route('jefe.consultas')">Limpiar</x-boton>
                @endif
            </form>
        </details>

        <section class="consultas-tabla" aria-label="Registro de consultas">
            @if ($consultas->isEmpty())
                <p class="registro-vacio">Todavía no hay consultas.</p>
            @else
                <div class="consultas-tabla-cabeza">
                    <span>Quién consultó</span>
                    <span>Registro consultado</span>
                    <span>Motivo</span>
                    <span>Fecha y hora</span>
                </div>
                @foreach ($consultas as $consulta)
                    <div class="consultas-fila">
                        <span>{{ $consulta->viewer->name }}</span>
                        <span>{{ $consulta->subject->name }}</span>
                        <span>{{ $consulta->reason ?: '—' }}</span>
                        <span class="consultas-fecha">{{ $consulta->created_at->locale('es')->isoFormat('D MMM YYYY') }} · {{ $consulta->created_at->format('H:i') }}</span>
                    </div>
                    <span class="sr">{{ $consulta->viewer->name }} consultó el registro de {{ $consulta->subject->name }}</span>
                @endforeach
            @endif
            <div class="consultas-pie">
                <span>{{ $consultas->count() }} {{ $consultas->count() === 1 ? 'consulta' : 'consultas' }}</span>
                <span>Registro de acceso</span>
                @if ($hayMasConsultas)
                    <x-boton
                        tipo="secundario"
                        :href="route('jefe.consultas', array_merge($filtrosConsultas, $mostrarTodas ? [] : ['todas' => 1]))"
                        class="consultas-filtro"
                    >
                        {{ $mostrarTodas ? 'Volver' : 'Ver todo' }}
                    </x-boton>
                @endif
            </div>
        </section>
    </div>
@endsection
