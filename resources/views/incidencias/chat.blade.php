<section class="comunicar-panel chat">
    @unless ($sinCabecera ?? false)
        <header class="comunicar-panel-cabeza">
            <h2>Chat</h2>
            <x-chip :tipo="$incidencia->estadoChip()">{{ $incidencia->estadoTexto() }}</x-chip>
        </header>
    @endunless

    <div class="chat-hilo">
        @if ($sinCabecera ?? false)
            @if ($incidencia->user_id !== auth()->id())
                <p class="chat-origen">De {{ $incidencia->autor->name }}</p>
            @elseif ($incidencia->destinatario)
                <p class="chat-origen">Para {{ $incidencia->destinatario->name }}</p>
            @endif
            <p class="chat-origen">{{ $incidencia->cuerpo }}</p>
        @endif
        @forelse ($incidencia->mensajes as $mensaje)
            <article class="chat-burbuja {{ $mensaje->user_id === auth()->id() ? 'chat-mia' : '' }}">
                <strong>{{ $mensaje->autor->name }}</strong>
                <p>{!! nl2br(e($mensaje->cuerpo)) !!}</p>
                <time>{{ \App\Support\Tiempo::fecha($mensaje->created_at) }} {{ $mensaje->created_at->format('H:i') }}</time>
            </article>
        @empty
            <p class="chat-vacio">Todavía no hay mensajes.</p>
        @endforelse
    </div>

    @if ($incidencia->estaResuelta())
        <p class="chat-cerrado">Resuelta. El chat está cerrado.</p>
        <form class="chat-escribir" aria-disabled="true">
            <textarea disabled placeholder="El chat está cerrado."></textarea>
            <button class="boton boton-principal" type="button" disabled>Enviar</button>
        </form>
    @else
        <form method="post" action="{{ route('incidencias.mensaje', $incidencia) }}" class="chat-escribir">
            @csrf
            <label class="chat-etiqueta" for="chat-cuerpo">Escribe un mensaje</label>
            <textarea id="chat-cuerpo" name="cuerpo" maxlength="1000" rows="2" required placeholder="Escribe un mensaje">{{ old('cuerpo') }}</textarea>
            <button class="boton boton-principal" type="submit">Enviar</button>
        </form>
    @endif
</section>
