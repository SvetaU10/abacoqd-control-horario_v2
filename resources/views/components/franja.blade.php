<div {{ $attributes->merge(['class' => 'franja']) }} role="status">
    <div class="franja-linea">
        <p>{{ $slot }}</p>
        @isset($primero)
            {{ $primero }}
        @endisset
        @isset($segundo)
            {{ $segundo }}
        @endisset
    </div>
</div>
