@extends('layouts.app', ['ancha' => true, 'titulo' => 'Editar alta'])

@section('contenido')
    @php
        $finContrato = session()->hasOldInput() ? old('ends_on') : $persona->ends_on?->toDateString();
    @endphp
    <a class="volver" href="{{ route('equipo.show', $persona) }}">Volver a {{ $persona->name }}</a>
    <h1>Editar alta · {{ $persona->name }}</h1>
    <p class="secundario">Aquí se corrigen el nombre y el fin de contrato. El cambio y su motivo quedan en el historial de la cuenta.</p>

    <h2>Datos que no se cambian aquí</h2>
    <ul class="lista alta-fija">
        <li>
            <span>Correo</span>
            <strong>{{ $persona->email }}</strong>
            <span class="secundario">Lo cambia la propia persona desde Mi cuenta, con confirmación en el correo nuevo.</span>
        </li>
        <li>
            <span>Inicio del registro</span>
            <strong>{{ $persona->starts_on?->format('d/m/Y') ?? 'Sin indicar' }}</strong>
            <span class="secundario">No se cambia: los días ya registrados dependen de esta fecha.</span>
        </li>
        <li>
            <span>Sede</span>
            <strong>{{ $persona->sede?->name ?? 'Sin asignar' }}</strong>
            <span class="secundario">Tiene su propio formulario en la ficha. Los días anteriores conservan su sede.</span>
        </li>
        <li>
            <span>Horario</span>
            <strong>{{ $horario?->texto() ?? 'Sin asignar' }}</strong>
            <span class="secundario">Tiene su propio formulario en la ficha. Cada horario vale desde su fecha.</span>
        </li>
    </ul>
    <p><a class="enlace" href="{{ route('equipo.show', $persona) }}">Ir a la ficha para cambiar la sede o el horario</a></p>

    <h2>Corregir el alta</h2>
    <form method="post" action="{{ route('equipo.update', $persona) }}" class="formulario">
        @csrf
        @method('patch')
        <label for="name">Nombre</label>
        <input id="name" name="name" type="text" value="{{ old('name', $persona->name) }}" minlength="2" maxlength="80" autocomplete="off" required>
        @error('name') <p class="campo-error" role="alert">{{ $message }}</p> @enderror

        <label for="ends_on">Fin de contrato</label>
        <x-fecha id="ends_on" name="ends_on" :value="$finContrato" :min="$persona->starts_on?->toDateString()" />
        <p class="secundario">Déjalo vacío si el contrato es indefinido. Si lo adelantas, los días posteriores dejan de contar como previstos.</p>
        @if ($persona->ends_on && $persona->ends_on->lt(now()->subDay()->startOfDay()))
            <p class="secundario">El contrato terminó el {{ $persona->ends_on->format('d/m/Y') }}. Si lo amplías, los días entre el {{ $persona->ends_on->copy()->addDay()->format('d/m/Y') }} y ayer quedan sin contrato y no cuentan como previstos, porque no podía fichar en ellos. Desde hoy cuenta con normalidad.</p>
        @endif
        @error('ends_on') <p class="campo-error" role="alert">{{ $message }}</p> @enderror

        <label for="motivo">Motivo del cambio</label>
        <textarea id="motivo" name="motivo" minlength="3" maxlength="300" required>{{ old('motivo') }}</textarea>
        @error('motivo') <p class="campo-error" role="alert">{{ $message }}</p> @enderror

        <button class="boton boton-principal" type="submit">Guardar cambios</button>
    </form>
@endsection
