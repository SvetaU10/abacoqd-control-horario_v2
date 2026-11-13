@extends('layouts.app', ['ancha' => true, 'titulo' => $persona->name])

@section('contenido')
    <a class="volver" href="{{ route('equipo.index') }}">Volver al equipo</a>
    <h1>{{ $persona->name }}</h1>
    <p>{{ $persona->email }}</p>
    <p class="secundario">Sede {{ $persona->sede?->name ?? 'sin asignar' }} en {{ $persona->sede?->municipality ?? $persona->municipality }}. El registro cuenta desde {{ $persona->starts_on ? \App\Support\Tiempo::fecha($persona->starts_on) : 'sin fecha' }}{{ $persona->ends_on ? ', hasta '.\App\Support\Tiempo::fecha($persona->ends_on) : ', sin fecha de fin' }}.</p>
    @if ($deBaja)
        <p class="ficha-baja" role="status">
            <x-icono nombre="user-minus" />
            <span>{{ $persona->baja_on ? 'De baja. Último día en la empresa: '.$persona->baja_on->format('d/m/Y').'. El registro se conserva.' : 'De baja sin fecha registrada: mientras no la tenga, sus días siguen contando como previstos. El registro se conserva.' }}</span>
        </p>
    @elseif ($bajaProgramada)
        <p class="ficha-baja" role="status">
            <x-icono nombre="user-minus" />
            <span>Baja prevista: último día el {{ $persona->baja_on->format('d/m/Y') }}. Conserva el acceso hasta el final de ese día.</span>
        </p>
    @endif
    <p>
        <a class="boton boton-secundario" href="{{ route('equipo.registro', $persona) }}">Ver registro</a>
        @if (auth()->user()->esJefa() && $persona->registraJornada())
            <a class="boton boton-secundario" href="{{ route('equipo.vacaciones.saldo', $persona) }}">Saldo de vacaciones</a>
        @endif
        <a class="boton boton-secundario boton-con-icono" href="{{ route('equipo.edit', $persona) }}">
            <x-icono nombre="pencil" />
            Editar alta
        </a>
    </p>

    <h2>Sede</h2>
    <p class="secundario">El cambio se aplica desde hoy. Los días anteriores conservan la sede y el calendario que tenían.</p>
    <form method="post" action="{{ route('equipo.centro', $persona) }}" class="formulario">
        @csrf
        <label for="centro">Centro de trabajo</label>
        <select id="centro" name="centro" required>
            @foreach ($centros as $centro)
                <option value="{{ $centro->name }}" @selected($persona->sede?->id === $centro->id)>{{ $centro->etiqueta() }}</option>
            @endforeach
        </select>
        <button class="boton boton-secundario" type="submit">Guardar sede</button>
    </form>

    <h2>Horario</h2>
    <ul class="lista">
        @forelse ($horarios as $horario)
            <li>
                <span>Desde {{ \App\Support\Tiempo::fecha($horario->effective_from) }}@if ($horario->tipoJornada()) · {{ $horario->tipoJornada()->etiqueta() }}@endif</span>
                <strong>{{ $horario->texto() }}</strong>
            </li>
        @empty
            <li>Sin horario.</li>
        @endforelse
    </ul>

    <form method="post" action="{{ route('equipo.horario', $persona) }}" class="formulario">
        @csrf
        <label for="effective_from">El horario nuevo vale desde</label>
        <x-fecha id="effective_from" name="effective_from" :min="now()->toDateString()" required />
        <p class="secundario">Los días anteriores conservan su horario.</p>
        <label for="morning_start">Empieza</label>
        <x-hora id="morning_start" name="morning_start" valor="09:00" />
        <label for="morning_end">Fin de la mañana</label>
        <x-hora id="morning_end" name="morning_end" valor="14:00" />
        <label for="afternoon_start">Vuelta</label>
        <x-hora id="afternoon_start" name="afternoon_start" valor="15:00" />
        <label for="afternoon_end">Fin de la jornada</label>
        <x-hora id="afternoon_end" name="afternoon_end" valor="18:00" />
        <button class="boton boton-secundario" type="submit">Guardar horario</button>
    </form>

    <h2>Ausencias</h2>
    <ul class="lista">
        @forelse ($ausencias as $ausencia)
            <li>
                <span>{{ \App\Support\Tiempo::fecha($ausencia->absence_date) }}</span>
                <strong>{{ $ausencia->tipoTexto() }}</strong>
                <details>
                    <summary>Corregir ausencia</summary>
                    <p class="secundario">La anulación queda en el historial. No modifica solicitudes anteriores.</p>
                    <form method="post" action="{{ route('equipo.ausencia.anular', ['user' => $persona, 'ausencia' => $ausencia]) }}">
                        @csrf
                        <label for="motivo-ausencia-{{ $ausencia->id }}">Motivo de la corrección</label>
                        <input id="motivo-ausencia-{{ $ausencia->id }}" name="motivo" type="text" maxlength="500" required>
                        <button class="boton boton-secundario" type="submit">Anular ausencia</button>
                    </form>
                </details>
            </li>
        @empty
            <li>Sin ausencias previstas.</li>
        @endforelse
    </ul>
    <form method="post" action="{{ route('equipo.ausencia', $persona) }}" class="formulario">
        @csrf
        <label for="absence_date">Día</label>
        <x-fecha id="absence_date" name="absence_date" required />
        <label for="tipo">Tipo</label>
        <select id="tipo" name="tipo" required>
            <option value="vacaciones">Vacaciones</option>
            <option value="permiso">Permiso</option>
        </select>
        <button class="boton boton-secundario" type="submit">Guardar ausencia</button>
    </form>

    @if ($ausenciasAnuladas->isNotEmpty())
        <h3>Historial de correcciones de ausencias</h3>
        <ul class="lista">
            @foreach ($ausenciasAnuladas as $evento)
                <li>
                    <span>{{ \App\Support\Tiempo::fecha($evento->absence_date) }} · {{ $evento->tipo === 'permiso' ? 'Permiso' : 'Vacaciones' }} anuladas</span>
                    <span>{{ $evento->motivo }}</span>
                    <span class="secundario">{{ $evento->actor?->name ?? 'Responsable' }} · {{ \App\Support\Tiempo::fecha($evento->created_at) }} {{ $evento->created_at->format('H:i') }}</span>
                </li>
            @endforeach
        </ul>
    @endif

    <h2>Baja y reincorporación</h2>
    @if ($esUnoMismo)
        <p class="secundario">Tu propia baja la gestiona otro responsable.</p>
    @elseif ($bajaAnulable)
        @php $bajaDeHoy = $deBaja; @endphp
        <p class="secundario">
            @if ($bajaDeHoy)
                La baja tiene fecha de hoy. Hasta el final del día puedes anularla: recupera el acceso y no queda ningún hueco. Queda anotado en el historial.
            @else
                Si ya no se va, anula la baja prevista. Queda anotado en el historial.
            @endif
        </p>
        <button class="boton boton-secundario" type="button" data-dialogo-abre="anular-baja">{{ $bajaDeHoy ? 'Anular baja' : 'Anular baja prevista' }}</button>
        <dialog class="dialogo-hoy dialogo-consulta" data-dialogo="anular-baja" aria-labelledby="titulo-anular-baja"
            @if ($abrirReincorporar || $errors->has('motivo_reincorporacion')) data-abierto @endif>
            <div class="dialogo-consulta-cabeza">
                <h2 id="titulo-anular-baja">Anular la baja de {{ $persona->name }}</h2>
                <form method="dialog">
                    <button class="dialogo-consulta-cerrar" type="submit" aria-label="Cerrar">×</button>
                </form>
            </div>
            <p class="dialogo-consulta-ayuda">
                @if ($bajaDeHoy)
                    La baja con fecha {{ $persona->baja_on->format('d/m/Y') }} se anula. Vuelve a tener acceso y sigue en el equipo sin cambios.
                @else
                    La baja prevista para el {{ $persona->baja_on->format('d/m/Y') }} se anula y sigue en el equipo sin cambios.
                @endif
            </p>
            <form method="post" action="{{ route('equipo.reincorporar', $persona) }}" class="formulario dialogo-consulta-formulario">
                @csrf
                <label for="motivo_anular_baja">Motivo</label>
                <textarea id="motivo_anular_baja" name="motivo_reincorporacion" minlength="3" maxlength="300" required>{{ old('motivo_reincorporacion') }}</textarea>
                @error('motivo_reincorporacion') <p class="campo-error" role="alert">{{ $message }}</p> @enderror
                <div class="dialogo-consulta-acciones">
                    <button class="boton boton-secundario" type="button" data-dialogo-cerrar>Cancelar</button>
                    <button class="boton boton-principal" type="submit">Anular baja</button>
                </div>
            </form>
        </dialog>
    @elseif ($deBaja)
        @if ($bajaSinFecha)
            <p class="secundario">Esta cuenta se cerró sin fecha de baja. Ponle el último día en la empresa para que los días siguientes dejen de contar como previstos. No cambia el acceso.</p>
            <button class="boton boton-secundario boton-con-icono" type="button" data-dialogo-abre="baja">
                <x-icono nombre="user-minus" />
                Poner fecha a la baja
            </button>
            <dialog class="dialogo-hoy dialogo-consulta" data-dialogo="baja" aria-labelledby="titulo-fecha-baja"
                @if ($errors->hasAny(['fecha_baja', 'motivo_baja'])) data-abierto @endif>
                <div class="dialogo-consulta-cabeza">
                    <h2 id="titulo-fecha-baja">Fecha de baja de {{ $persona->name }}</h2>
                    <form method="dialog">
                        <button class="dialogo-consulta-cerrar" type="submit" aria-label="Cerrar">×</button>
                    </form>
                </div>
                <p class="dialogo-consulta-ayuda">La cuenta ya está cerrada, así que el último día es hoy o un día pasado. Los días posteriores dejan de contar como previstos y el registro se conserva.</p>
                <form method="post" action="{{ route('equipo.baja', $persona) }}" class="formulario dialogo-consulta-formulario">
                    @csrf
                    <label for="fecha_baja">Último día en la empresa</label>
                    <x-fecha id="fecha_baja" name="fecha_baja"
                        :value="old('fecha_baja', $hoy->toDateString())"
                        :min="$persona->starts_on?->toDateString()"
                        :max="$hoy->toDateString()" required />
                    @error('fecha_baja') <p class="campo-error" role="alert">{{ $message }}</p> @enderror
                    <label for="motivo_baja">Motivo</label>
                    <textarea id="motivo_baja" name="motivo_baja" minlength="3" maxlength="300" required>{{ old('motivo_baja') }}</textarea>
                    @error('motivo_baja') <p class="campo-error" role="alert">{{ $message }}</p> @enderror
                    <div class="dialogo-consulta-acciones">
                        <button class="boton boton-secundario" type="button" data-dialogo-cerrar>Cancelar</button>
                        <button class="boton boton-principal" type="submit">Guardar fecha</button>
                    </div>
                </form>
            </dialog>
        @endif
        <p class="secundario">Al reincorporar, los días entre la baja y la vuelta quedan sin contrato y no cuentan como previstos. Los días anteriores conservan su registro.</p>
        <button class="boton boton-principal boton-con-icono" type="button" data-dialogo-abre="reincorporar">
            <x-icono nombre="user-check" />
            Reincorporar
        </button>
        <dialog class="dialogo-hoy dialogo-consulta" data-dialogo="reincorporar" aria-labelledby="titulo-reincorporar"
            @if ($abrirReincorporar || $errors->hasAny(['fecha_reincorporacion', 'motivo_reincorporacion'])) data-abierto @endif>
            <div class="dialogo-consulta-cabeza">
                <h2 id="titulo-reincorporar">Reincorporar a {{ $persona->name }}</h2>
                <form method="dialog">
                    <button class="dialogo-consulta-cerrar" type="submit" aria-label="Cerrar">×</button>
                </form>
            </div>
            <p class="dialogo-consulta-ayuda">
                @if ($persona->baja_on)
                    Su último día fue el {{ $persona->baja_on->format('d/m/Y') }}. Elige el día en que vuelve: el acceso se abre al guardar.
                @else
                    La baja no tiene fecha registrada: los días anteriores se quedan como estaban. Elige el día en que vuelve: el acceso se abre al guardar.
                @endif
                Si eliges un día anterior a hoy, los días laborables desde la vuelta hasta ayer cuentan como previstos aunque no pudo fichar en ellos.
                @if ($persona->ends_on && $persona->ends_on->lt($hoy))
                    Su fin de contrato fue el {{ $persona->ends_on->format('d/m/Y') }}; después podrás corregirlo en Editar alta.
                @endif
            </p>
            <form method="post" action="{{ route('equipo.reincorporar', $persona) }}" class="formulario dialogo-consulta-formulario">
                @csrf
                <label for="fecha_reincorporacion">Vuelve el</label>
                <x-fecha id="fecha_reincorporacion" name="fecha_reincorporacion"
                    :value="old('fecha_reincorporacion', $hoy->toDateString())"
                    :min="$persona->baja_on ? $persona->baja_on->copy()->addDay()->toDateString() : $persona->starts_on?->toDateString()"
                    :max="$hoy->toDateString()" required />
                @error('fecha_reincorporacion') <p class="campo-error" role="alert">{{ $message }}</p> @enderror
                <label for="motivo_reincorporacion">Motivo</label>
                <textarea id="motivo_reincorporacion" name="motivo_reincorporacion" minlength="3" maxlength="300" required>{{ old('motivo_reincorporacion') }}</textarea>
                @error('motivo_reincorporacion') <p class="campo-error" role="alert">{{ $message }}</p> @enderror
                <div class="dialogo-consulta-acciones">
                    <button class="boton boton-secundario" type="button" data-dialogo-cerrar>Cancelar</button>
                    <button class="boton boton-principal" type="submit">Reincorporar</button>
                </div>
            </form>
        </dialog>
    @else
        <p class="secundario">Elige el último día en la empresa. Los días siguientes no cuentan como previstos y el registro se conserva.</p>
        <button class="boton boton-secundario boton-con-icono" type="button" data-dialogo-abre="baja">
            <x-icono nombre="user-minus" />
            Dar de baja
        </button>
        <dialog class="dialogo-hoy dialogo-consulta" data-dialogo="baja" aria-labelledby="titulo-baja"
            @if ($errors->hasAny(['fecha_baja', 'motivo_baja'])) data-abierto @endif>
            <div class="dialogo-consulta-cabeza">
                <h2 id="titulo-baja">Dar de baja a {{ $persona->name }}</h2>
                <form method="dialog">
                    <button class="dialogo-consulta-cerrar" type="submit" aria-label="Cerrar">×</button>
                </form>
            </div>
            <p class="dialogo-consulta-ayuda">Si el último día es hoy o ya pasó, el acceso se cierra al guardar, así que antes tiene que cerrar cualquier tramo abierto. Si es un día futuro, conserva el acceso hasta el final de ese día. Hasta el final del último día puedes anular la baja; después, reincorporarlo.</p>
            <form method="post" action="{{ route('equipo.baja', $persona) }}" class="formulario dialogo-consulta-formulario">
                @csrf
                <label for="fecha_baja">Último día en la empresa</label>
                <x-fecha id="fecha_baja" name="fecha_baja"
                    :value="old('fecha_baja', $hoy->toDateString())"
                    :min="$persona->starts_on?->toDateString()"
                    :max="$hoy->copy()->addYear()->toDateString()" required />
                @error('fecha_baja') <p class="campo-error" role="alert">{{ $message }}</p> @enderror
                <label for="motivo_baja">Motivo</label>
                <textarea id="motivo_baja" name="motivo_baja" minlength="3" maxlength="300" required>{{ old('motivo_baja') }}</textarea>
                @error('motivo_baja') <p class="campo-error" role="alert">{{ $message }}</p> @enderror
                <div class="dialogo-consulta-acciones">
                    <button class="boton boton-secundario" type="button" data-dialogo-cerrar>Cancelar</button>
                    <button class="boton boton-principal" type="submit">Confirmar baja</button>
                </div>
            </form>
        </dialog>
    @endif

    @if ($eventosCuenta->isNotEmpty())
        <h3>Historial de la cuenta</h3>
        <ul class="lista historial-cuenta">
            @foreach ($eventosCuenta as $evento)
                <li>
                    <span><strong>{{ $evento->titulo() }}</strong>@foreach ($evento->cambios() as $linea) {{ $linea }}@endforeach</span>
                    <span>{{ $evento->motivo }}</span>
                    <span class="secundario">{{ $evento->actor?->name ?? 'Responsable' }} · {{ $evento->created_at->format('d/m/Y H:i') }}</span>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($consultas->isNotEmpty())
        <h2>Consultas</h2>
        <ul class="lista">
            @foreach ($consultas as $visita)
                <li>
                    <span>{{ $visita->viewer->name }}</span>
                    <span class="secundario">{{ \App\Support\Tiempo::fecha($visita->created_at) }} {{ $visita->created_at->format('H:i') }}</span>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
