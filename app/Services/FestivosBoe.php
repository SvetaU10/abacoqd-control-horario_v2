<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class FestivosBoe
{
    private const COMUNIDADES = [
        'ES-AN' => ['andalucia'], 'ES-AR' => ['aragon'], 'ES-AS' => ['asturias'],
        'ES-IB' => ['illes balears'], 'ES-CN' => ['canarias (1)'], 'ES-CB' => ['cantabria'],
        'ES-CM' => ['castilla-la mancha'], 'ES-CL' => ['castilla y leon'],
        'ES-CT' => ['cataluna (2)'], 'ES-EX' => ['extremadura'], 'ES-GA' => ['galicia'],
        'ES-MD' => ['com. madrid', 'com. de madrid'], 'ES-MC' => ['region murcia'],
        'ES-NC' => ['c. foral navarra'], 'ES-PV' => ['pais vasco'], 'ES-RI' => ['la rioja'],
        'ES-VC' => ['comunitat valenciana'], 'ES-CE' => ['ciudad de ceuta'], 'ES-ML' => ['ciudad de melilla'],
    ];

    /**
     * @param  list<array<string,string>>  $festivos
     * @return array{estado:string,mensaje:string,fechas:list<string>,dias:list<array<string,string>>,fuentes:list<array{nombre:string,url:string}>}
     */
    public function consultar(int $anio, string $comunidad, array $festivos): array
    {
        $pendiente = ['estado' => 'pendiente_revision', 'mensaje' => 'No se pudo comprobar la relación anual del BOE. Se conserva el calendario válido.', 'fechas' => [], 'dias' => [], 'fuentes' => []];
        if (! isset(self::COMUNIDADES[$comunidad]) || in_array($comunidad, ['ES-CN', 'ES-CT'], true)) {
            return [...$pendiente, 'mensaje' => 'Este territorio requiere comprobar también las excepciones insulares o territoriales de su calendario.'];
        }
        $referencias = [];
        foreach ($festivos as $dia) {
            if (($dia['ambito'] ?? '') !== 'pueblo' && preg_match('/\bBOE-A-\d{4}-\d+\b/', $dia['fuente'] ?? '', $coincidencia)) {
                $referencias[$coincidencia[0]] = true;
            }
        }
        if (count($referencias) !== 1) {
            return [...$pendiente, 'mensaje' => 'No hay una referencia anual inequívoca del BOE para comprobar estos festivos.'];
        }
        $referencia = array_key_first($referencias);
        $url = 'https://www.boe.es/diario_boe/xml.php?id='.$referencia;
        $fuentes = [['nombre' => 'BOE · relación oficial de fiestas laborales '.$anio, 'url' => $url]];
        try {
            $respuesta = Http::accept('application/xml')->connectTimeout(3)->timeout(12)->get($url);
            if (! $respuesta->successful() || strlen($respuesta->body()) > 2_000_000) {
                return [...$pendiente, 'fuentes' => $fuentes];
            }
            $dias = $this->leer($respuesta->body(), $referencia, $anio, $comunidad, $url);

            return ['estado' => 'confirmado', 'mensaje' => 'Festivos nacionales y autonómicos comprobados en el BOE.', 'fechas' => array_column($dias, 'date'), 'dias' => $dias, 'fuentes' => $fuentes];
        } catch (ConnectionException|RuntimeException $error) {
            return [...$pendiente, 'requiere_revision' => $error instanceof RuntimeException, 'mensaje' => $error instanceof RuntimeException ? $error->getMessage() : $pendiente['mensaje'], 'fuentes' => $fuentes];
        }
    }

    /** @return list<array<string,string>> */
    private function leer(string $xml, string $referencia, int $anio, string $comunidad, string $url): array
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new RuntimeException('El documento del BOE contiene una estructura XML no admitida.');
        }
        $anterior = libxml_use_internal_errors(true);
        try {
            $documento = new DOMDocument;
            if (! $documento->loadXML($xml, LIBXML_NONET)) {
                throw new RuntimeException('La publicación del BOE no tiene un XML válido.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($anterior);
        }
        $consulta = new DOMXPath($documento);
        $titulo = $this->texto($consulta->evaluate('string(/documento/metadatos/titulo)'));
        if ($consulta->evaluate('string(/documento/metadatos/identificador)') !== $referencia
            || ! preg_match('/relacion de fiestas laborales para el ano '.$anio.'\.?$/D', Str::ascii(Str::lower($titulo)))) {
            throw new RuntimeException('La referencia del BOE no es el calendario laboral del año solicitado.');
        }
        $tablas = $consulta->query('/documento/texto/table');
        if ($tablas->length !== 1) {
            throw new RuntimeException('El formato de la tabla oficial del BOE ha cambiado.');
        }
        $tabla = $tablas->item(0);
        $cabeceras = $consulta->query('./thead/tr[last()]/th', $tabla);
        if ($cabeceras->length !== 19) {
            throw new RuntimeException('La tabla del BOE no identifica las diecinueve comunidades y ciudades.');
        }
        $columna = null;
        foreach ($cabeceras as $indice => $cabecera) {
            if (in_array(Str::ascii(Str::lower($this->texto($cabecera->textContent))), self::COMUNIDADES[$comunidad], true)) {
                if ($columna !== null) {
                    throw new RuntimeException('La comunidad aparece más de una vez en la tabla del BOE.');
                }
                $columna = $indice + 1;
            }
        }
        if ($columna === null) {
            throw new RuntimeException('No se reconoce la comunidad en la tabla anual del BOE.');
        }
        $meses = ['enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12];
        $mes = null;
        $dias = [];
        foreach ($consulta->query('./tbody/tr', $tabla) as $fila) {
            $celdas = $consulta->query('./td', $fila);
            if ($celdas->length !== 20) {
                throw new RuntimeException('El BOE contiene una fila de calendario con columnas inesperadas.');
            }
            $primera = $this->texto($celdas->item(0)->textContent);
            $nombreMes = Str::lower($primera);
            if (isset($meses[$nombreMes])) {
                $mes = $meses[$nombreMes];

                continue;
            }
            if ($mes === null || ! preg_match('/^(\d{1,2})\s+(.+)$/u', $primera, $partes)) {
                throw new RuntimeException('No se reconoce la fecha de una fila del BOE.');
            }
            $marca = $this->texto($celdas->item($columna)->textContent);
            if ($marca === '') {
                continue;
            }
            if (! in_array($marca, ['*', '**', '***'], true) || ! checkdate($mes, (int) $partes[1], $anio)) {
                throw new RuntimeException('El BOE contiene una fecha o código que requiere revisión.');
            }
            $fecha = sprintf('%04d-%02d-%02d', $anio, $mes, (int) $partes[1]);
            if (isset($dias[$fecha])) {
                throw new RuntimeException('El calendario del BOE repite una fecha para esta comunidad.');
            }
            $dias[$fecha] = ['date' => $fecha, 'name' => rtrim($partes[2], '.'), 'ambito' => $marca === '***' ? 'comunidad' : 'estado', 'fuente' => $referencia, 'url' => $url];
        }
        if (count($dias) !== 12) {
            throw new RuntimeException('La relación del BOE no permite confirmar doce fiestas generales para esta comunidad y año.');
        }
        ksort($dias);

        return array_values($dias);
    }

    private function texto(string $texto): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace("\u{00a0}", ' ', $texto)));
    }
}
