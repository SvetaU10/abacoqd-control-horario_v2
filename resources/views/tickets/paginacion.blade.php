@if ($paginas->hasPages())
    <nav class="acciones-secundarias" aria-label="Páginas de tickets">
        @if ($paginas->previousPageUrl())
            <x-boton :href="$paginas->previousPageUrl()" rel="prev">Anterior</x-boton>
        @endif
        <span>Página {{ $paginas->currentPage() }} de {{ $paginas->lastPage() }} · {{ $paginas->total() }} tickets</span>
        @if ($paginas->nextPageUrl())
            <x-boton :href="$paginas->nextPageUrl()" rel="next">Siguiente</x-boton>
        @endif
    </nav>
@endif
