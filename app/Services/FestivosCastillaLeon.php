<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class FestivosCastillaLeon
{
    public const DATASET = 'https://analisis.datosabiertos.jcyl.es/explore/dataset/fiestas-locales-calendario-de-fiestas-de-caracter-local/';

    public const API = 'https://analisis.datosabiertos.jcyl.es/api/explore/v2.1/catalog/datasets/fiestas-locales-calendario-de-fiestas-de-caracter-local/records';

    private const PROVINCIAS = [
        '05' => 'Ávila', '09' => 'Burgos', '24' => 'León', '34' => 'Palencia', '37' => 'Salamanca',
        '40' => 'Segovia', '42' => 'Soria', '47' => 'Valladolid', '49' => 'Zamora',
    ];

    /**
     * La API mezcla años y entidades; sólo confirma fechas inequívocas del municipio solicitado.
     *
     * @param  array{ine:string,nombre:string,provincia:string,comunidad_codigo:string}  $municipio
     * @return array{estado:string,mensaje:string,fechas:list<string>,fuentes:list<array{nombre:string,url:string}>}
     */
    public function consultar(int $anio, array $municipio): array
    {
        $ine = $municipio['ine'] ?? null;
        $nombre = $municipio['nombre'] ?? null;
        $provincia = $municipio['provincia'] ?? null;
        if ($anio < 2000 || $anio > 2100 || ! is_string($ine) || ! preg_match('/^\d{5}$/D', $ine)
            || ! is_string($nombre) || trim($nombre) === '' || ! is_string($provincia)
            || ($municipio['comunidad_codigo'] ?? null) !== 'ES-CL'
            || ! isset(self::PROVINCIAS[substr($ine, 0, 2)])
            || $this->normalizarNombre($provincia) !== $this->normalizarNombre(self::PROVINCIAS[substr($ine, 0, 2)])) {
            return $this->resultado('pendiente_revision', 'No se ha podido identificar un municipio de Castilla y León con su código INE y provincia.');
        }

        $parametros = ['where' => 'ine='.(int) $ine, 'limit' => 100, 'order_by' => 'fecha_fiesta,municipio,nombre_fiesta'];
        $fuentes = [
            ['nombre' => 'Junta de Castilla y León · fiestas locales · CC BY 4.0', 'url' => self::DATASET],
            ['nombre' => 'API oficial JCyL · registros del municipio', 'url' => self::API.'?'.http_build_query($parametros)],
        ];
        try {
            $filas = $this->descargar($parametros);
        } catch (ConnectionException|RuntimeException $error) {
            return $this->resultado('pendiente_revision', 'No se puede comprobar el calendario local oficial ahora. '.$error->getMessage(), fuentes: $fuentes);
        }

        $fechas = [];
        $entidades = [];
        $incompleto = false;
        $contradiccion = false;
        foreach ($filas as $fila) {
            if (! is_array($fila) || ! $this->coincideIne($fila['ine'] ?? null, $ine)
                || ! is_string($fila['provincia'] ?? null) || $this->normalizarNombre($fila['provincia']) !== $this->normalizarNombre($provincia)
                || ! is_string($fila['municipio'] ?? null) || trim($fila['municipio']) === '') {
                return $this->resultado('conflicto', 'La fuente oficial ha devuelto una identidad municipal o provincial distinta de la solicitada.', fuentes: $fuentes);
            }
            $fecha = $fila['fecha_fiesta'] ?? null;
            if ($fecha === null || $fecha === '') {
                $incompleto = true;

                continue;
            }
            if (! is_string($fecha) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $fecha, $partes)
                || ! checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1])) {
                return $this->resultado('pendiente_revision', 'La fuente oficial contiene una fecha no válida; hace falta revisar su publicación.', fuentes: $fuentes);
            }
            if ((int) $partes[1] !== $anio) {
                continue;
            }
            $entidades[$this->normalizarNombre($fila['municipio'])] = true;
            $festividad = $fila['nombre_fiesta'] ?? null;
            if (! is_string($festividad) || trim($festividad) === '' || $this->esProvisional($fila)) {
                $incompleto = true;

                continue;
            }
            if (isset($fechas[$fecha]) && $fechas[$fecha] !== $this->normalizarNombre($festividad)) {
                $contradiccion = true;
            }
            $fechas[$fecha] = $this->normalizarNombre($festividad);
        }

        if (count($entidades) > 1 || ($entidades !== [] && ! isset($entidades[$this->normalizarNombre($nombre)]))) {
            return [...$this->resultado('pendiente_revision', 'El código INE contiene fiestas de entidades o localidades que no se pueden asignar al centro sin revisión.', fuentes: $fuentes), 'requiere_revision' => true];
        }
        if ($contradiccion) {
            return $this->resultado('conflicto', 'Hay versiones distintas para la misma fiesta local. Debe comprobarse la corrección oficial.', fuentes: $fuentes);
        }
        $fechas = array_keys($fechas);
        sort($fechas);
        if (count($fechas) > 2) {
            return $this->resultado('conflicto', 'La fuente oficial contiene más de dos fechas locales para el municipio y año. No se pueden ordenar sus posibles correcciones.', fuentes: $fuentes);
        }
        if ($incompleto || count($fechas) !== 2) {
            return $this->resultado('pendiente_revision', 'No están disponibles las dos fiestas locales definitivas de este municipio y año; no se reutilizan fechas de otros años.', $fechas, $fuentes);
        }

        return $this->resultado('confirmado', 'Las fiestas locales coinciden con el municipio, provincia y año publicados por la Junta de Castilla y León.', $fechas, $fuentes);
    }

    /** @param array<string,int|string> $parametros @return list<array<string,mixed>> */
    private function descargar(array $parametros): array
    {
        $filas = [];
        $total = null;
        do {
            $respuesta = Http::acceptJson()->connectTimeout(3)->timeout(8)->get(self::API, $parametros + ['offset' => count($filas)]);
            if (! $respuesta->successful()) {
                throw new RuntimeException('La API oficial responde con HTTP '.$respuesta->status().'.');
            }
            if (strlen($respuesta->body()) > 262144) {
                throw new RuntimeException('La respuesta municipal supera el tamaño esperado.');
            }
            $datos = $respuesta->json();
            if (! is_array($datos) || ! is_int($datos['total_count'] ?? null) || $datos['total_count'] < 0
                || ! is_array($datos['results'] ?? null) || ! array_is_list($datos['results'])
                || count($datos['results']) > 100 || $datos['total_count'] > 200
                || ($total !== null && $total !== $datos['total_count'])
                || count($datos['results']) !== min(100, $datos['total_count'] - count($filas))) {
                throw new RuntimeException('La respuesta está incompleta, ha cambiado durante la consulta o tiene un formato no válido.');
            }
            $total = $datos['total_count'];
            $filas = [...$filas, ...$datos['results']];
        } while (count($filas) < $total);

        return $filas;
    }

    private function coincideIne(mixed $valor, string $ine): bool
    {
        return (is_int($valor) || is_string($valor)) && preg_match('/^\d{1,5}$/D', (string) $valor)
            && str_pad((string) $valor, 5, '0', STR_PAD_LEFT) === $ine;
    }

    private function normalizarNombre(string $valor): string
    {
        $valor = Str::lower(Str::ascii(trim($valor)));
        if (preg_match('/^(.+?)(?:,\s*|\s*\()(el|la|los|las)\)?$/D', $valor, $partes)) {
            $valor = $partes[2].' '.$partes[1];
        }

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $valor));
    }

    /** @param array<string,mixed> $fila */
    private function esProvisional(array $fila): bool
    {
        if (in_array($fila['provisional'] ?? false, [true, 1, '1', 'true'], true)) {
            return true;
        }
        $texto = implode(' ', array_filter([$fila['nombre_fiesta'] ?? null, $fila['estado'] ?? null, $fila['observaciones'] ?? null], 'is_string'));

        return (bool) preg_match('/\b(provisional|pendiente|sin comunicar|no comunicad[oa]|sin determinar|por determinar|anulad[oa])\b/', $this->normalizarNombre($texto));
    }

    /** @param list<string> $fechas @param list<array{nombre:string,url:string}> $fuentes @return array{estado:string,mensaje:string,fechas:list<string>,fuentes:list<array{nombre:string,url:string}>} */
    private function resultado(string $estado, string $mensaje, array $fechas = [], array $fuentes = []): array
    {
        return ['estado' => $estado, 'mensaje' => $mensaje, 'fechas' => $fechas, 'fuentes' => $fuentes];
    }
}
