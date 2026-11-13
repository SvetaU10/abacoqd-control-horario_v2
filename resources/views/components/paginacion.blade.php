@props(['paginas', 'etiqueta' => 'resultados'])

@if ($paginas->hasPages())
    <nav class="paginacion" aria-label="Páginas de {{ $etiqueta }}">
        @if ($paginas->previousPageUrl())
            <a class="boton boton-secundario" href="{{ $paginas->previousPageUrl() }}" rel="prev">Anterior</a>
        @endif
        <span>Página {{ $paginas->currentPage() }} de {{ $paginas->lastPage() }} · {{ $paginas->total() }} {{ $etiqueta }}</span>
        @if ($paginas->nextPageUrl())
            <a class="boton boton-secundario" href="{{ $paginas->nextPageUrl() }}" rel="next">Siguiente</a>
        @endif
    </nav>
@endif
