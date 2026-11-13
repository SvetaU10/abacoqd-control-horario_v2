@extends('layouts.app', ['ancha' => true, 'titulo' => 'Saldo de vacaciones'])

@section('contenido')
    <a class="volver" href="{{ route('equipo.show', $persona) }}">Volver a {{ $persona->name }}</a>
    <h1>Saldo de vacaciones · {{ $persona->name }}</h1>
    <form method="get" action="{{ route('equipo.vacaciones.saldo', $persona) }}" class="formulario">
        <label for="elegir-anio">Año</label>
        <input id="elegir-anio" name="anio" type="number" min="2000" max="2100" value="{{ $anio }}" required>
        <button class="boton boton-secundario" type="submit">Consultar año</button>
    </form>
    @if ($problemaCobertura)
        <p role="alert">{{ $problemaCobertura }} Confirma el calendario antes de ajustar el saldo.</p>
    @else
    <p>Vacaciones aprobadas registradas: <strong>{{ $aprobados }}</strong> días laborables.</p>
    <p>Solicitudes pendientes reservadas: <strong>{{ $pendientes }}</strong> días laborables.</p>
    <p>Saldo restante confirmado: <strong>{{ $restantes === null ? 'Sin confirmar' : $restantes.' días' }}</strong>.</p>
    <p class="secundario">Indica cuántos días quedan ahora, antes de descontar las solicitudes pendientes. Las aprobaciones posteriores se descontarán automáticamente.</p>
    <form method="post" action="{{ route('equipo.vacaciones.saldo.guardar', $persona) }}" class="formulario">
        @csrf
        <input type="hidden" name="anio" value="{{ $anio }}">
        <input type="hidden" name="aprobados_esperados" value="{{ $aprobados }}">
        <input type="hidden" name="ultimo_ajuste_esperado" value="{{ $ultimoAjuste }}">
        <input type="hidden" name="operacion" value="{{ $operacion }}">
        <label for="saldo">Días restantes a confirmar</label>
        <input id="saldo" name="saldo" type="number" min="0" max="366" value="{{ old('saldo', $restantes) }}" required>
        <label for="motivo">Motivo</label>
        <textarea id="motivo" name="motivo" maxlength="500" required>{{ old('motivo') }}</textarea>
        <button class="boton boton-primario" type="submit">Confirmar saldo</button>
    </form>
    @endif
    <h2>Historial de ajustes de {{ $anio }}</h2>
    <ul class="lista">
        @forelse ($ajustes as $ajuste)
            <li><span>{{ $ajuste->created_at->format('d/m/Y H:i') }} · {{ $ajuste->actor?->name }}</span>
                <strong>{{ $ajuste->saldo_deseado }} días restantes</strong>
                <span>{{ $ajuste->motivo }}</span></li>
        @empty
            <li>Sin ajustes confirmados.</li>
        @endforelse
    </ul>
@endsection
