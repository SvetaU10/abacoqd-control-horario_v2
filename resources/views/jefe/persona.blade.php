@extends('layouts.app', ['titulo' => $persona->name])

@section('contenido')
    <p><a class="enlace" href="{{ route('jefe.index') }}">Volver al estado del equipo</a></p>
    <h1>{{ $persona->name }}</h1>
    <p>{{ $persona->email }}</p>
    <p class="secundario">Centro en {{ $persona->municipality }}. El registro cuenta desde {{ $persona->starts_on ? \App\Support\Tiempo::fecha($persona->starts_on) : 'sin fecha' }}{{ $persona->ends_on ? ', hasta '.\App\Support\Tiempo::fecha($persona->ends_on) : ', sin fecha de fin' }}.</p>

    <div class="estado estado-consulta" aria-live="polite">
        <img class="icono-estado icono-pequeno" src="{{ asset('marca/iconos/'.$estado['icono']) }}" alt="">
        <div>
            <p class="etiqueta-estado">Hoy</p>
            <p class="estado-texto estado-texto-corto">{{ $estado['texto'] }}</p>
        </div>
    </div>

    @if ($antiguos > 0)
        <p class="franja" role="status">
            <span class="franja-texto">
                <strong>{{ $antiguos === 1 ? 'Tiene 1 cierre sin resolver' : 'Tiene '.$antiguos.' cierres sin resolver' }} de hace más de {{ $diasHastaAvisar }} días.</strong>
                <span>Abre su registro indicando el motivo de consulta.</span>
            </span>
        </p>
    @endif

    <p>
        <a class="boton boton-principal" href="{{ route('jefe.registro', $persona) }}">Ver registro</a>
    </p>
    <p class="secundario">Para abrir el registro hace falta un motivo. La consulta queda escrita.</p>

    <h2>Horario</h2>
    <p class="secundario">Los cambios de horario se hacen desde <a class="enlace" href="{{ route('equipo.show', $persona) }}">Equipo</a>. Cada horario nuevo vale desde su fecha y los días anteriores conservan el suyo.</p>
    <ul class="lista">
        @forelse ($horarios as $horario)
            <li>
                <span>Desde {{ \App\Support\Tiempo::fecha($horario->effective_from) }}</span>
                <strong>{{ $horario->texto() }}</strong>
            </li>
        @empty
            <li>Sin horario.</li>
        @endforelse
    </ul>

    <h2>Ausencias</h2>
    <ul class="lista">
        @forelse ($ausencias as $ausencia)
            <li>
                <span>{{ \App\Support\Tiempo::fecha($ausencia->absence_date) }}</span>
                <strong>{{ $ausencia->tipoTexto() }}</strong>
            </li>
        @empty
            <li>Sin ausencias previstas.</li>
        @endforelse
    </ul>

    @if ($persona->estaDeBajaEl(now()))
        <p class="secundario">De baja{{ $persona->baja_on ? ', último día en la empresa el '.$persona->baja_on->format('d/m/Y') : '' }}. Acceso cerrado. El registro se conserva.</p>
    @elseif ($persona->tieneBajaProgramadaEl(now()))
        <p class="secundario">Baja prevista: último día el {{ $persona->baja_on->format('d/m/Y') }}. La gestiona el responsable desde <a class="enlace" href="{{ route('equipo.show', $persona) }}">Equipo</a>.</p>
    @endif

    @if ($consultas->isNotEmpty())
        <h2>Consultas de su registro</h2>
        <ul class="lista">
            @foreach ($consultas as $visita)
                <li>
                    <span>{{ $visita->viewer->name }}@if ($visita->reason). {{ $visita->reason }}@endif</span>
                    <span class="secundario">{{ \App\Support\Tiempo::fecha($visita->created_at) }} {{ $visita->created_at->format('H:i') }}</span>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
