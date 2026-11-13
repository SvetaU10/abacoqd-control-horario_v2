@extends('layouts.app', ['titulo' => 'Incidencia'])

@section('contenido')
    <div class="comunicar">
        <header class="comunicar-cabecera">
            <a class="volver" href="{{ route('incidencias.index') }}">Volver a incidencias</a>
            <h1>Incidencia</h1>
        </header>

        <div class="comunicar-rejilla">
            <section class="comunicar-panel comunicar-ficha">
                <header class="ventana-incidencia-cabeza">
                    <span class="incidencia-inicial" aria-hidden="true">{{ mb_substr($incidencia->autor->name, 0, 1) }}</span>
                    <div>
                        <p class="comunicar-autor">{{ $incidencia->autor->name }}</p>
                        @if ($incidencia->destinatario)
                            <p class="secundario">Para {{ $incidencia->destinatario->name }}</p>
                        @endif
                        <p class="secundario">
                            {{ \App\Support\Tiempo::fecha($incidencia->created_at) }} a las {{ $incidencia->created_at->format('H:i') }}
                            · {{ $incidencia->estadoTexto() }}
                        </p>
                    </div>
                </header>
                <div class="ventana-incidencia-cuerpo comunicar-cuerpo">
                    <p>{!! nl2br(e($incidencia->cuerpo)) !!}</p>
                </div>
                @unless ($incidencia->estaResuelta())
                    <div class="comunicar-acciones">
                        @if ($incidencia->estaPendiente())
                            <form method="post" action="{{ route('incidencias.curso', $incidencia) }}">
                                @csrf
                                <button class="boton boton-secundario" type="submit">En curso</button>
                            </form>
                        @endif
                        <form method="post" action="{{ route('incidencias.resolver', $incidencia) }}">
                            @csrf
                            <button class="boton boton-principal" type="submit">Resolver</button>
                        </form>
                    </div>
                @endunless
            </section>

            @include('incidencias.chat')
        </div>
    </div>
@endsection
