@php
    $textoMunicipio = $sedeMunicipio ? $sedeMunicipio->municipality.' · '.($sedeMunicipio->province ?: $sedeMunicipio->community) : '';
@endphp
<div class="cal-municipio" data-sede-municipio data-url="{{ route('gestion.municipios') }}">
    <label for="{{ $prefijo }}-municipio">Municipio</label>
    <input id="{{ $prefijo }}-municipio" type="search" value="{{ $textoMunicipio }}" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="{{ $prefijo }}-opciones" aria-describedby="{{ $prefijo }}-ayuda" placeholder="Busca municipio o código INE" required>
    <input type="hidden" name="municipio_ine" value="{{ $sedeMunicipio?->municipio_ine }}" data-sede-ine>
    <input type="hidden" name="municipality" value="{{ $sedeMunicipio?->municipality }}" data-sede-nombre>
    <input type="hidden" name="community" value="{{ $sedeMunicipio?->community }}" data-sede-comunidad>
    <div id="{{ $prefijo }}-opciones" class="cal-municipio-opciones" role="listbox" aria-label="Municipios encontrados" hidden></div>
    <p id="{{ $prefijo }}-ayuda" class="cal-ayuda" data-sede-ayuda aria-live="polite">{{ $sedeMunicipio ? ($sedeMunicipio->province ? $sedeMunicipio->province.' · ' : '').$sedeMunicipio->community.' · España' : 'Selecciona un resultado. Provincia y comunidad se completan automáticamente.' }}</p>
</div>
