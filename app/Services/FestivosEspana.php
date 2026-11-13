<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class FestivosEspana
{
    public const API = 'https://calendariolaboralespana.es/api/festivos/';

    public const DOCUMENTACION = 'https://calendariolaboralespana.es/datos-abiertos/';

    /**
     * Compara proveedores para cualquier INE, sin atribuir una comprobación oficial propia.
     *
     * @param  array<string,string>  $municipio
     * @param  list<array<string,string>>  $festivos
     * @return array{estado:string,mensaje:string,fuentes:list<array{nombre:string,url:string}>}
     */
    public function consultar(int $anio, array $municipio, array $festivos): array
    {
        $ine = $municipio['ine'] ?? null;
        if ($anio < 2000 || $anio > 2100 || ! is_string($ine) || ! preg_match('/^\d{5}$/D', $ine)) {
            return $this->resultado('pendiente_revision', 'No se puede consultar la concordancia entre proveedores sin un año y código INE válidos.');
        }
        $url = self::API.'?'.http_build_query(['ine' => $ine, 'year' => $anio]);
        $fuentes = [
            ['nombre' => 'Calendario Laboral España · segundo proveedor · CC BY 4.0 · a partir del BOE y los boletines oficiales', 'url' => $url],
            ['nombre' => 'Calendario Laboral España · documentación y licencia', 'url' => self::DOCUMENTACION],
        ];
        foreach ($festivos as $dia) {
            if (is_array($dia) && is_string($dia['fuente'] ?? null) && is_string($dia['url'] ?? null)
                && filter_var($dia['url'], FILTER_VALIDATE_URL) && parse_url($dia['url'], PHP_URL_SCHEME) === 'https') {
                $fuentes[] = ['nombre' => $dia['fuente'], 'url' => $dia['url']];
            }
        }
        $fuentes = collect($fuentes)->unique('url')->values()->all();

        try {
            $datos = $this->descargar($url);
            if ($datos === null) {
                return $this->resultado('parcial', 'El segundo proveedor todavía no ofrece datos para este municipio y año. Se conserva el calendario válido.', $fuentes);
            }
            if (($datos['municipio']['ine'] ?? null) !== $ine || ! is_array($datos['anios'] ?? null)
                || ! array_is_list($datos['anios']) || count($datos['anios']) !== 1 || ($datos['anios'][0]['year'] ?? null) !== $anio) {
                return $this->resultado('conflicto', 'La respuesta del segundo proveedor no corresponde exactamente al código INE y año solicitados.', $fuentes);
            }
            foreach (['nombre' => 'nombre', 'provincia' => 'provincia'] as $campo => $solicitado) {
                if (! is_string($datos['municipio'][$campo] ?? null) || ! is_string($municipio[$solicitado] ?? null)
                    || array_intersect($this->alias($datos['municipio'][$campo]), $this->alias($municipio[$solicitado])) === []) {
                    return $this->resultado('conflicto', 'El nombre o la provincia del segundo proveedor no corresponden al municipio seleccionado.', $fuentes);
                }
            }
            $calendario = $datos['anios'][0];
            $primero = $this->normalizar($festivos, $anio, false);
            $segundo = $this->normalizar($calendario['festivos'] ?? null, $anio, true);
            if (($calendario['nacionalOk'] ?? null) !== true || ($calendario['localOk'] ?? null) !== true || $segundo['provisional']) {
                return $this->resultado('parcial', 'El segundo proveedor indica datos nacionales o locales provisionales o pendientes para este municipio y año. No se confirma la concordancia entre proveedores.', $fuentes);
            }
            if ($primero['claves'] !== $segundo['claves']) {
                return $this->resultado('conflicto', 'Los proveedores discrepan en fechas o ámbitos del calendario de este municipio y año. Puede faltar una fiesta local, territorial o insular; se conserva el calendario válido.', $fuentes);
            }
            if ($primero['generales'] < 12 || $primero['generales'] > 13 || $primero['locales'] < 1 || $primero['locales'] > 2) {
                return $this->resultado('parcial', 'Aunque las fechas coinciden, no hay cobertura suficiente o hay una excepción que requiere revisión: deben constar entre doce y trece fiestas generales y una o dos locales, sin deducir fechas que no estén publicadas.', $fuentes);
            }

            return $this->resultado('confirmado', 'Calendario cargado por concordancia entre proveedores: mismas fechas y ámbitos para el código INE y año solicitados. Las referencias oficiales se conservan para su consulta.', $fuentes);
        } catch (ConnectionException|RuntimeException $error) {
            return $this->resultado('pendiente_revision', 'No se ha podido comprobar la concordancia entre proveedores. '.$error->getMessage().' Se conserva el calendario válido.', $fuentes);
        }
    }

    /** @return array<string,mixed>|null */
    private function descargar(string $url): ?array
    {
        return Cache::remember('calendario.espana.'.hash('sha256', $url), now()->addMinutes(10), function () use ($url): ?array {
            $respuesta = Http::acceptJson()->connectTimeout(3)->timeout(12)->get($url);
            if ($respuesta->notFound()) {
                return null;
            }
            if (! $respuesta->successful()) {
                throw new RuntimeException('El segundo proveedor responde con HTTP '.$respuesta->status().'.');
            }
            if (strlen($respuesta->body()) > 262144 || ! is_array($respuesta->json())) {
                throw new RuntimeException('La respuesta del segundo proveedor tiene un formato o tamaño no válido.');
            }

            return $respuesta->json();
        });
    }

    /** @return array{claves:list<string>,provisional:bool,locales:int,generales:int} */
    private function normalizar(mixed $filas, int $anio, bool $segundo): array
    {
        if (! is_array($filas) || ! array_is_list($filas)) {
            throw new RuntimeException('Una lista de fiestas no tiene un formato válido.');
        }
        $claves = [];
        $provisional = false;
        $fechasLocales = [];
        $fechasGenerales = [];
        foreach ($filas as $dia) {
            if (! is_array($dia)) {
                throw new RuntimeException('El calendario contiene una fiesta con un formato no válido.');
            }
            $fecha = $dia[$segundo ? 'fecha' : 'date'] ?? null;
            $nombre = $dia[$segundo ? 'nombre' : 'name'] ?? null;
            $ambito = $segundo ? match ($dia['ambito'] ?? null) {
                'nacional' => 'estado', 'autonomico' => 'comunidad', 'local' => 'pueblo', default => null,
            } : ($dia['ambito'] ?? null);
            if (! is_string($fecha) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $fecha, $partes)
                || (int) $partes[1] !== $anio || ! checkdate((int) $partes[2], (int) $partes[3], $anio)
                || ! is_string($nombre) || trim($nombre) === '' || ! in_array($ambito, ['estado', 'comunidad', 'pueblo'], true)) {
                throw new RuntimeException('El calendario contiene una fecha, nombre o clasificación de ámbito no válida.');
            }
            if ($segundo && ($dia['estado'] ?? null) !== 'verificado') {
                $provisional = true;
            }
            $clave = $fecha.':'.$ambito;
            if (! isset($claves[$clave])) {
                $claves[$clave] = true;
                if ($ambito === 'pueblo') {
                    $fechasLocales[$fecha] = true;
                } else {
                    $fechasGenerales[$fecha] = true;
                }
            }
        }
        $claves = array_keys($claves);
        sort($claves);
        $locales = count($fechasLocales);
        $generales = count($fechasGenerales);

        return compact('claves', 'provisional', 'locales', 'generales');
    }

    /** @return list<string> */
    private function alias(string $nombre): array
    {
        return array_map(static function (string $parte): string {
            $parte = Str::lower(Str::ascii(trim($parte)));
            if (preg_match('/^(.+),\s*(el|la|los|las|els|les|illes)$/D', $parte, $coincidencia)) {
                $parte = $coincidencia[2].' '.$coincidencia[1];
            }

            return trim(preg_replace('/[^a-z0-9]+/', ' ', $parte));
        }, explode('/', $nombre));
    }

    /** @param list<array{nombre:string,url:string}> $fuentes @return array{estado:string,mensaje:string,fuentes:list<array{nombre:string,url:string}>} */
    private function resultado(string $estado, string $mensaje, array $fuentes = []): array
    {
        return compact('estado', 'mensaje', 'fuentes');
    }
}
