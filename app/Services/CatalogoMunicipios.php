<?php

namespace App\Services;

use App\Models\Municipio;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

class CatalogoMunicipios
{
    public const URL = 'https://festivos.io/v1/ref/municipios.json';

    public const SNAPSHOT = 'data/municipios-2026.json';

    public const SNAPSHOT_SHA256 = '93452d46e657eeea86b04f31d16f31949e72646330d424ec075514834cada2f7';

    private const CAMPOS = ['ine', 'nombre', 'provincia', 'provincia_codigo', 'comunidad', 'comunidad_codigo'];

    private const AUTONOMIAS = [
        '01' => 'ES-PV', '02' => 'ES-CM', '03' => 'ES-VC', '04' => 'ES-AN', '05' => 'ES-CL',
        '06' => 'ES-EX', '07' => 'ES-IB', '08' => 'ES-CT', '09' => 'ES-CL', '10' => 'ES-EX',
        '11' => 'ES-AN', '12' => 'ES-VC', '13' => 'ES-CM', '14' => 'ES-AN', '15' => 'ES-GA',
        '16' => 'ES-CM', '17' => 'ES-CT', '18' => 'ES-AN', '19' => 'ES-CM', '20' => 'ES-PV',
        '21' => 'ES-AN', '22' => 'ES-AR', '23' => 'ES-AN', '24' => 'ES-CL', '25' => 'ES-CT',
        '26' => 'ES-RI', '27' => 'ES-GA', '28' => 'ES-MD', '29' => 'ES-AN', '30' => 'ES-MC',
        '31' => 'ES-NC', '32' => 'ES-GA', '33' => 'ES-AS', '34' => 'ES-CL', '35' => 'ES-CN',
        '36' => 'ES-GA', '37' => 'ES-CL', '38' => 'ES-CN', '39' => 'ES-CB', '40' => 'ES-CL',
        '41' => 'ES-AN', '42' => 'ES-CL', '43' => 'ES-CT', '44' => 'ES-AR', '45' => 'ES-CM',
        '46' => 'ES-VC', '47' => 'ES-CL', '48' => 'ES-PV', '49' => 'ES-CL', '50' => 'ES-AR',
        '51' => 'ES-CE', '52' => 'ES-ML',
    ];

    /** @return list<array{ine:string,nombre:string,provincia:string,provincia_codigo:string,comunidad:string,comunidad_codigo:string}> */
    public function buscar(?string $texto = null, int $limite = 25): array
    {
        return DB::transaction(function () use ($texto, $limite): array {
            $estado = $this->estado();
            if (! $estado['disponible']) {
                throw new RuntimeException('El catálogo municipal no está instalado. Ejecuta catalogo:actualizar --snapshot.');
            }
            $texto = $this->normalizar((string) $texto);
            $limite = max(1, min(26, $limite));
            $clave = 'municipios:ranking-v2:'.$estado['sha256'].':'.hash('sha256', $texto.':'.$limite);

            return Cache::remember($clave, now()->addHour(), function () use ($texto, $limite): array {
                $consulta = Municipio::query()->select(self::CAMPOS);
                if ($texto !== '') {
                    $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $texto);
                    $palabras = array_values(array_filter(explode(' ', str_replace(',', ' ', $literal)), fn (string $palabra): bool => $palabra !== ''));
                    if ($palabras === []) {
                        return [];
                    }
                    foreach ($palabras as $palabra) {
                        $consulta->whereRaw("busqueda LIKE ? ESCAPE '!'", ['%'.$palabra.'%']);
                    }
                    $alternativos = $this->nombresAlternativos($texto);
                    $prefijos = array_map(fn (string $nombre): string => str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $nombre).'%', $alternativos);
                    $exactos = implode(',', array_fill(0, count($alternativos), '?'));
                    $empieza = implode(' OR ', array_fill(0, count($prefijos), "nombre_normalizado LIKE ? ESCAPE '!'"));
                    $contiene = implode(' AND ', array_fill(0, count($palabras), "nombre_normalizado LIKE ? ESCAPE '!'"));
                    $consulta->orderByRaw(
                        "CASE WHEN ine = ? OR nombre_normalizado IN ($exactos) THEN 0 WHEN ($empieza) THEN 1 WHEN ($contiene) THEN 2 ELSE 3 END",
                        [$texto, ...$alternativos, ...$prefijos, ...array_map(fn (string $palabra): string => '%'.$palabra.'%', $palabras)],
                    );
                }

                return $consulta->orderBy('nombre_normalizado')->orderBy('ine')->limit($limite)->get()->toArray();
            });
        }, 3);
    }

    /** @return array{ine:string,nombre:string,provincia:string,provincia_codigo:string,comunidad:string,comunidad_codigo:string} */
    public function resolver(string $ine): array
    {
        if (! preg_match('/^\d{5}$/D', $ine)) {
            throw new InvalidArgumentException('Selecciona un municipio del catálogo.');
        }
        $municipio = Municipio::query()->select(self::CAMPOS)->find($ine);
        if ($municipio === null) {
            throw new InvalidArgumentException('Ese código de municipio no está en el catálogo local.');
        }

        return $municipio->toArray();
    }

    /** @return array{disponible:bool,total:int,fuente:?string,origen:?string,referencia:?string,actualizado_at:?string,sha256:?string} */
    public function estado(): array
    {
        $estado = DB::table('catalogo_municipios')->where('id', 1)->first();

        return [
            'disponible' => $estado !== null,
            'total' => (int) ($estado?->total ?? 0),
            'fuente' => $estado?->fuente,
            'origen' => $estado?->origen,
            'referencia' => $estado?->referencia,
            'actualizado_at' => $estado?->actualizado_at,
            'sha256' => $estado?->sha256,
        ];
    }

    /** @return array<string,mixed> */
    public function instalarSnapshot(): array
    {
        if ($this->estado()['disponible']) {
            return $this->estado();
        }
        $ruta = database_path(self::SNAPSHOT);
        if (! is_file($ruta) || ! hash_equals(self::SNAPSHOT_SHA256, hash_file('sha256', $ruta))) {
            throw new RuntimeException('La copia incluida del catálogo municipal falta o no supera su comprobación de integridad.');
        }
        $datos = $this->decodificar(file_get_contents($ruta));

        return $this->guardar($datos, (string) ($datos['downloaded_at'] ?? ''), true);
    }

    /** @return array<string,mixed> */
    public function actualizar(): array
    {
        try {
            $respuesta = Http::acceptJson()->connectTimeout(3)->timeout(20)->get(self::URL);
        } catch (ConnectionException $error) {
            throw new RuntimeException('No se pudo conectar con el catálogo. Se conserva la versión local.', previous: $error);
        }
        if (! $respuesta->successful()) {
            throw new RuntimeException('El catálogo respondió HTTP '.$respuesta->status().'. Se conserva la versión local.');
        }

        return $this->guardar($this->decodificar($respuesta->body()), now()->utc()->toIso8601String());
    }

    /** @return array<string,mixed> */
    private function decodificar(string $contenido): array
    {
        if (strlen($contenido) > 4_000_000) {
            throw new RuntimeException('El catálogo supera el tamaño esperado. Se conserva la versión local.');
        }
        try {
            $datos = json_decode($contenido, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('El catálogo no contiene JSON válido. Se conserva la versión local.', previous: $error);
        }
        if (! is_array($datos)) {
            throw new RuntimeException('El catálogo no tiene un formato válido. Se conserva la versión local.');
        }

        return $datos;
    }

    /** @param array<string,mixed> $datos @return array<string,mixed> */
    private function guardar(array $datos, string $fecha, bool $soloSiVacio = false): array
    {
        $filas = $this->validar($datos);
        $hash = hash('sha256', json_encode($filas, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        try {
            $fecha = Carbon::parse($fecha)->utc()->toDateTimeString();
        } catch (\Throwable $error) {
            throw new RuntimeException('La fecha del catálogo no es válida.', previous: $error);
        }
        DB::transaction(function () use ($datos, $filas, $hash, $fecha, $soloSiVacio): void {
            $anterior = DB::table('catalogo_municipios')->where('id', 1)->lockForUpdate()->first();
            if ($soloSiVacio && $anterior !== null) {
                return;
            }
            if ($anterior?->sha256 !== $hash) {
                DB::table('municipios')->delete();
                foreach (array_chunk($filas, 100) as $lote) {
                    DB::table('municipios')->insert($lote);
                }
            }
            DB::table('catalogo_municipios')->updateOrInsert(['id' => 1], [
                'total' => count($filas), 'fuente' => $datos['source'], 'origen' => self::URL,
                'referencia' => $datos['source_url'], 'actualizado_at' => $fecha, 'sha256' => $hash,
            ]);
        }, 3);

        return $this->estado();
    }

    /** @param array<string,mixed> $datos @return list<array<string,string>> */
    private function validar(array $datos): array
    {
        $filas = $datos['municipalities'] ?? null;
        $referencia = $datos['source_url'] ?? null;
        if (! is_array($filas) || ! array_is_list($filas) || count($filas) < 8000 || count($filas) > 10000
            || ($datos['count'] ?? null) !== count($filas)
            || ! is_string($datos['source'] ?? null) || trim($datos['source']) === '' || mb_strlen($datos['source']) > 500
            || ! is_string($referencia) || mb_strlen($referencia) > 500 || filter_var($referencia, FILTER_VALIDATE_URL) === false
            || parse_url($referencia, PHP_URL_SCHEME) !== 'https' || parse_url($referencia, PHP_URL_HOST) !== 'www.ine.es') {
            throw new RuntimeException('El catálogo nacional está incompleto o carece de una referencia válida del INE. Se conserva la versión local.');
        }
        $resultado = [];
        $provincias = [];
        $comunidades = [];
        foreach ($filas as $fila) {
            if (! is_array($fila) || ! is_string($fila['ine'] ?? null) || ! preg_match('/^\d{5}$/D', $fila['ine'])
                || ! is_string($fila['province'] ?? null) || ! isset(self::AUTONOMIAS[$fila['province']])
                || substr($fila['ine'], 0, 2) !== $fila['province']
                || ($fila['ccaa_iso'] ?? null) !== self::AUTONOMIAS[$fila['province']]
                || isset($resultado[$fila['ine']])) {
                throw new RuntimeException('El catálogo contiene códigos duplicados o una identidad territorial incoherente. Se conserva la versión local.');
            }
            foreach (['name' => 160, 'province_name' => 120, 'ccaa_name' => 120] as $campo => $maximo) {
                if (! is_string($fila[$campo] ?? null) || trim($fila[$campo]) === '' || mb_strlen($fila[$campo]) > $maximo) {
                    throw new RuntimeException('El catálogo contiene nombres territoriales inválidos. Se conserva la versión local.');
                }
            }
            $nombre = trim($fila['name']);
            $provincia = trim($fila['province_name']);
            $comunidad = trim($fila['ccaa_name']);
            if ((isset($provincias[$fila['province']]) && $provincias[$fila['province']] !== $provincia)
                || (isset($comunidades[$fila['ccaa_iso']]) && $comunidades[$fila['ccaa_iso']] !== $comunidad)) {
                throw new RuntimeException('El catálogo da nombres distintos a la misma provincia o comunidad. Se conserva la versión local.');
            }
            $resultado[$fila['ine']] = [
                'ine' => $fila['ine'], 'nombre' => $nombre, 'provincia' => $provincia,
                'provincia_codigo' => $fila['province'], 'comunidad' => $comunidad,
                'comunidad_codigo' => $fila['ccaa_iso'], 'nombre_normalizado' => $this->normalizar($nombre),
                'busqueda' => $this->normalizar($nombre.' '.$provincia.' '.$comunidad.' '.$fila['ine']),
            ];
            $provincias[$fila['province']] = $provincia;
            $comunidades[$fila['ccaa_iso']] = $comunidad;
        }
        if (count($provincias) !== 52) {
            throw new RuntimeException('El catálogo no cubre las 52 provincias y ciudades autónomas. Se conserva la versión local.');
        }
        ksort($resultado);

        return array_values($resultado);
    }

    private function normalizar(string $texto): string
    {
        return preg_replace('/\s+/u', ' ', Str::lower(Str::ascii(trim($texto))));
    }

    /** @return list<string> */
    private function nombresAlternativos(string $texto): array
    {
        $articulos = ['a', 'as', 'o', 'os', 'el', 'la', 'las', 'los', 'els', 'les', 'es', 'sa', 'ses', 'sos'];
        $variantes = [$texto];
        $patron = implode('|', $articulos);
        if (preg_match('/^('.$patron.') (.+)$/D', $texto, $partes)) {
            $variantes[] = $partes[2].', '.$partes[1];
        }
        if (preg_match('/^(.+), ('.$patron.')$/D', $texto, $partes)) {
            $variantes[] = $partes[2].' '.$partes[1];
        }
        foreach ($articulos as $articulo) {
            $variantes[] = $texto.', '.$articulo;
        }

        return array_values(array_unique($variantes));
    }
}
