<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

class FestivosValencia
{
    public const INDICE = 'https://habitatge.gva.es/es/web/dg-trabajo/calendario-laboral';

    public const API = 'https://dogv.gva.es/dogv-portal';

    public const MUNICIPAL = 'https://www.valencia.es/cas/atencion-ciudadana/horario-oficinas-atenci%C3%B3n-presencial';

    /**
     * El DOGV identifica por nombre, sin INE. Estos alias delimitan los
     * municipios cuya correspondencia se ha comprobado; no son coincidencias aproximadas.
     *
     * @var array<string,list<string>>
     */
    private const ALIAS_INE = [
        '03007' => ['alcosser'],
        '03096' => ['onil'],
        '03097' => ['orba'],
        '03103' => ['penaguila'],
        '12131' => ['villamalur'],
        '46068' => ['benissoda'],
        '46250' => ['valencia'],
    ];

    /**
     * @param  array{ine:string,nombre:string,provincia:string,comunidad_codigo:string}  $municipio
     * @return array{estado:string,mensaje:string,fechas:list<string>,fuentes:list<array{nombre:string,url:string}>}
     */
    public function consultar(int $anio, array $municipio): array
    {
        $fuentes = [['nombre' => 'Generalitat Valenciana · publicaciones y rectificaciones del calendario laboral', 'url' => self::INDICE]];
        $provincia = match (substr((string) ($municipio['ine'] ?? ''), 0, 2)) {
            '03' => 'alicante', '12' => 'castellon', '46' => 'valencia', default => null,
        };
        if ($anio < 2000 || $anio > 2100 || ($municipio['comunidad_codigo'] ?? '') !== 'ES-VC'
            || ! preg_match('/^\d{5}$/D', (string) ($municipio['ine'] ?? '')) || $provincia === null
            || ! is_string($municipio['nombre'] ?? null) || trim($municipio['nombre']) === ''
            || ! in_array($provincia, $this->alias((string) ($municipio['provincia'] ?? '')), true)
            || (isset(self::ALIAS_INE[$municipio['ine']]) && array_intersect($this->alias($municipio['nombre']), self::ALIAS_INE[$municipio['ine']]) === [])) {
            return $this->resultado('pendiente_revision', 'La identidad del municipio valenciano no se puede comprobar por su código INE, provincia y nombre.', [], $fuentes);
        }

        $publicaciones = $this->publicaciones($this->descargar(self::INDICE), $anio);
        if ($publicaciones === []) {
            return $this->resultado('pendiente_revision', 'La Generalitat todavía no ofrece una relación local legible para este año. No se reutiliza la del año anterior.', [], $fuentes);
        }
        $documentos = [];
        foreach ($publicaciones as $codigo) {
            $fuentes[] = ['nombre' => 'DOGV · '.$codigo, 'url' => 'https://dogv.gva.es/es/resultat-dogv?signatura='.$codigo];
            $id = $this->json(self::API.'/dogv/obtenerIdDogv/'.str_replace('/', '-', $codigo));
            if (! is_int($id) || $id < 1) {
                return $this->resultado('pendiente_revision', 'No se ha podido identificar una publicación oficial del calendario local.', [], $fuentes);
            }
            $documento = $this->json(self::API.'/disposicion/'.$id.'?lang=es_es');
            if (! is_array($documento) || ($documento['id'] ?? null) !== $id || ($documento['codigoInsercion'] ?? null) !== $codigo
                || ($documento['estado']['codigo'] ?? null) !== 'PUBLICADA'
                || ! $this->fechaPublicada($documento['fechaPublicacion'] ?? null)
                || ! str_contains($this->normalizar((string) ($documento['titulo'] ?? '')), 'calendario de fiestas locales')
                || ! preg_match('/para el (?:ano )?'.$anio.'\b/', $this->normalizar((string) ($documento['titulo'] ?? '')))
                || ! is_string($documento['texto'] ?? null)) {
                return $this->resultado('pendiente_revision', 'Una publicación del DOGV no permite comprobar su identidad, año, estado o fecha de publicación.', [], $fuentes);
            }
            $documentos[] = $documento;
        }
        usort($documentos, fn (array $uno, array $otro): int => [$uno['fechaPublicacion'], $uno['id']] <=> [$otro['fechaPublicacion'], $otro['id']]);
        $bases = array_values(array_filter($documentos, fn (array $doc): bool => str_contains($this->normalizar($doc['titulo']), 'por la que se aprueba')));
        if (count($bases) !== 1) {
            return $this->resultado('pendiente_revision', 'No se ha identificado una única resolución inicial del calendario local de este año.', [], $fuentes);
        }
        $base = $bases[0];
        $correcciones = $this->json(self::API.'/disposicion/'.$base['id'].'/correccionErrores?lang=es_es');
        if (! is_array($correcciones) || ! array_key_exists('analisisJuridicoActivo', $correcciones)
            || ! array_key_exists('analisisJuridicoPasivo', $correcciones)
            || $correcciones['analisisJuridicoActivo'] !== [] || $correcciones['analisisJuridicoPasivo'] !== []) {
            return [...$this->resultado('pendiente_revision', 'El DOGV señala correcciones adicionales que requieren revisión antes de confirmar las fiestas locales.', [], $fuentes), 'requiere_revision' => true];
        }

        $fechas = null;
        foreach ($documentos as $documento) {
            if ($documento['id'] === $base['id']) {
                continue;
            }
            $cambios = $this->cambios($documento['texto'], $anio);
            if ($cambios === null) {
                return [...$this->resultado('pendiente_revision', 'Una rectificación del calendario local tiene un formato que no se puede comprobar automáticamente.', [], $fuentes), 'requiere_revision' => true];
            }
            foreach ($cambios as $cambio) {
                if ($cambio['provincia'] !== $provincia || ! $this->mismoMunicipio($cambio['nombre'], $municipio)) {
                    continue;
                }
                $nuevas = $this->fechasDeTexto($cambio['nuevo'], $anio);
                $anteriores = $this->fechasDeTexto($cambio['anterior'], $anio);
                if ($nuevas === null || $anteriores === null) {
                    return $this->resultado('pendiente_revision', 'La rectificación de este municipio contiene fechas locales que necesitan revisión.', [], $fuentes);
                }
                if ($fechas !== null && $fechas !== $anteriores) {
                    return $this->resultado('conflicto', 'La rectificación del DOGV no corresponde a las fechas locales anteriores encontradas para este municipio.', [], $fuentes);
                }
                $fechas = $nuevas;
            }
        }

        if ($municipio['ine'] === '46250') {
            $fuentes[] = ['nombre' => 'Ayuntamiento de València · calendario laboral municipal', 'url' => self::MUNICIPAL];
            $municipales = $this->valenciaCapital($this->descargar(self::MUNICIPAL), $anio);
            if ($municipales === null) {
                return $this->resultado('pendiente_revision', 'El Ayuntamiento de València no ofrece las dos fiestas locales de este año en un formato comprobable.', [], $fuentes);
            }
            if ($fechas !== null && $fechas !== $municipales) {
                return $this->resultado('conflicto', 'Las fechas del Ayuntamiento de València y las rectificaciones del DOGV no coinciden. Revisa las fuentes oficiales.', [], $fuentes);
            }
            $fechas = $municipales;
        }
        if ($fechas === null) {
            return $this->resultado('pendiente_revision', 'Las fiestas de este municipio están en un anexo PDF del DOGV que todavía requiere revisión. No se han deducido de otros municipios.', [], $fuentes);
        }

        return $this->resultado('confirmado', 'Fiestas locales comprobadas en fuentes oficiales para el municipio y año solicitados, incluidas las rectificaciones publicadas.', $fechas, $fuentes);
    }

    /** @return list<string> */
    private function publicaciones(string $html, int $anio): array
    {
        $xpath = $this->xpath($html);
        $seleccionado = false;
        $codigos = [];
        foreach ($xpath->query('//h2 | //a') as $nodo) {
            if ($nodo->nodeName === 'h2') {
                $seleccionado = $this->normalizar($nodo->textContent) === 'calendario laboral '.$anio;

                continue;
            }
            if (! $seleccionado || ! str_contains($this->normalizar($nodo->textContent), 'fiestas locales')) {
                continue;
            }
            $url = html_entity_decode($nodo->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $host = parse_url($url, PHP_URL_HOST);
            if ($host !== null && ! in_array($host, ['dogv.gva.es', 'ceice.gva.es', 'habitatge.gva.es'], true)) {
                throw new RuntimeException('El índice de la Generalitat enlaza una publicación local fuera de sus dominios oficiales.');
            }
            if (! preg_match('~(?:signatura=|/)(\d{4})[/_](\d+)(?:_|&|$)~', $url, $partes)) {
                throw new RuntimeException('No se puede identificar una publicación local enlazada por la Generalitat.');
            }
            $codigos[] = $partes[1].'/'.$partes[2];
        }

        return array_values(array_unique($codigos));
    }

    /** @return list<array{provincia:string,nombre:string,anterior:string,nuevo:string}>|null */
    private function cambios(string $html, int $anio): ?array
    {
        $texto = $this->texto($html);
        $patron = '/Donde dice\s*:?\s*[«“"]([^:»”"]+):\s*(.+?)[»”"]\s*;?\s*Debe decir\s*:?\s*[«“"]([^:»”"]+):\s*(.+?)[»”"]/isu';
        preg_match_all($patron, $texto, $filas, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        preg_match_all('/Debe decir/iu', $texto, $esperados);
        if ($filas === [] || count($filas) !== count($esperados[0])) {
            return null;
        }
        $resultado = [];
        foreach ($filas as $fila) {
            $provincia = $this->provinciaEn(substr($texto, 0, $fila[0][1]), $anio);
            if ($provincia === null || $this->normalizar($fila[1][0]) !== $this->normalizar($fila[3][0])) {
                return null;
            }
            $resultado[] = ['provincia' => $provincia, 'nombre' => trim($fila[3][0]), 'anterior' => $fila[2][0], 'nuevo' => $fila[4][0]];
        }

        return $resultado;
    }

    private function provinciaEn(string $texto, int $anio): ?string
    {
        preg_match_all('/Relacion de fiestas locales (?:'.$anio.' en la provincia de |en la provincia de )(Alicante|Castellon|Valencia)(?: '.$anio.')?\b/i', Str::ascii($texto), $provincias);

        return $provincias[1] === [] ? null : strtolower(end($provincias[1]));
    }

    /** @return list<string>|null */
    private function valenciaCapital(string $html, int $anio): ?array
    {
        $xpath = $this->xpath($html);
        $tablas = [];
        foreach ($xpath->query('//h3') as $titulo) {
            if ($this->normalizar($titulo->textContent) === 'calendario laboral del ayuntamiento de valencia para '.$anio) {
                $tabla = $xpath->query('following-sibling::*[1][self::table]', $titulo)->item(0);
                if ($tabla !== null) {
                    $tablas[] = $tabla;
                }
            }
        }
        if (count($tablas) !== 1) {
            return null;
        }
        $fechas = [];
        foreach ($xpath->query('.//tr', $tablas[0]) as $fila) {
            $celdas = $xpath->query('./td', $fila);
            if ($celdas->length !== 2) {
                continue;
            }
            $nombre = $this->normalizar($celdas->item(1)->textContent);
            if (! in_array($nombre, ['san vicente martir', 'san vicente ferrer'], true)) {
                continue;
            }
            if (isset($fechas[$nombre])) {
                return null;
            }
            $fecha = $this->fechaSimple($celdas->item(0)->textContent, $anio);
            if ($fecha === null) {
                return null;
            }
            $fechas[$nombre] = $fecha;
        }
        $fechas = array_values(array_unique($fechas));
        sort($fechas);

        return count($fechas) === 2 ? $fechas : null;
    }

    /** @return list<string>|null */
    private function fechasDeTexto(string $texto, int $anio): ?array
    {
        $partes = preg_split('/\s*;\s*|\s+y\s+(?=\d+\s+(?:de\s+)?[a-z])/u', $this->normalizar($texto));
        if (count($partes) !== 2) {
            return null;
        }
        $fechas = [];
        foreach ($partes as $parte) {
            $fecha = $this->fechaSimple($parte, $anio, true);
            if ($fecha === null) {
                return null;
            }
            $fechas[] = $fecha;
        }
        sort($fechas);

        return count(array_unique($fechas)) === 2 ? $fechas : null;
    }

    private function fechaSimple(string $texto, int $anio, bool $descripcion = false): ?string
    {
        $meses = ['enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12];
        $patron = '/^(\d{1,2})\s+(?:de\s+)?('.implode('|', array_keys($meses)).')'.($descripcion ? '(?:\s*[,\.]|$)' : '$').'/u';
        if (! preg_match($patron, $this->normalizar($texto), $partes) || ! checkdate($meses[$partes[2]], (int) $partes[1], $anio)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $anio, $meses[$partes[2]], (int) $partes[1]);
    }

    /** @param array<string,mixed> $municipio */
    private function mismoMunicipio(string $nombre, array $municipio): bool
    {
        return isset(self::ALIAS_INE[$municipio['ine']])
            && array_intersect($this->alias($nombre), self::ALIAS_INE[$municipio['ine']]) !== [];
    }

    /** @return list<string> */
    private function alias(string $nombre): array
    {
        return array_values(array_unique(array_map(fn (string $parte): string => $this->normalizar($parte), explode('/', $nombre))));
    }

    private function normalizar(string $texto): string
    {
        return trim(preg_replace('/\s+/u', ' ', Str::lower(Str::ascii(html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8')))));
    }

    private function texto(string $html): string
    {
        return html_entity_decode(strip_tags(preg_replace('~</(?:p|div|tr|h[1-6])>|<br\s*/?>~i', "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function xpath(string $html): DOMXPath
    {
        $documento = new DOMDocument;
        $anterior = libxml_use_internal_errors(true);
        $documento->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        return new DOMXPath($documento);
    }

    private function fechaPublicada(mixed $fecha): bool
    {
        return is_string($fecha) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $fecha, $partes)
            && checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1]) && $fecha <= now()->toDateString();
    }

    private function json(string $url): mixed
    {
        try {
            return json_decode($this->descargar($url), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('El DOGV ha devuelto una respuesta no válida al comprobar las fiestas locales.', previous: $error);
        }
    }

    private function descargar(string $url): string
    {
        return Cache::remember('calendario.gva.'.hash('sha256', $url), now()->addMinutes(10), function () use ($url): string {
            try {
                $respuesta = Http::connectTimeout(5)->timeout(12)
                    ->retry(2, 250, fn (\Exception $error): bool => $error instanceof ConnectionException, throw: false)
                    ->get($url);
            } catch (ConnectionException $error) {
                Log::warning('Conexión de calendario valenciano fallida', ['fuente' => parse_url($url, PHP_URL_HOST), 'error' => $error->getMessage()]);
                throw new RuntimeException('No se puede conectar con la fuente oficial de fiestas locales valencianas.', previous: $error);
            }
            if ($respuesta->notFound()) {
                return '';
            }
            if (! $respuesta->successful()) {
                throw new RuntimeException('La fuente oficial de fiestas locales valencianas no está disponible (HTTP '.$respuesta->status().').');
            }

            return $respuesta->body();
        });
    }

    /** @param list<string> $fechas @param list<array{nombre:string,url:string}> $fuentes @return array{estado:string,mensaje:string,fechas:list<string>,fuentes:list<array{nombre:string,url:string}>} */
    private function resultado(string $estado, string $mensaje, array $fechas, array $fuentes): array
    {
        return compact('estado', 'mensaje', 'fechas', 'fuentes');
    }
}
