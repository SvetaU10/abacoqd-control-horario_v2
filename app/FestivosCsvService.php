<?php

namespace App;

use App\Models\Centro;
use App\Models\Festivo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FestivosCsvService
{
    /**
     * @return array{filas: list<array<string, mixed>>, errores: list<string>}
     */
    public function leer(UploadedFile $archivo): array
    {
        $ruta = $archivo->getRealPath();
        if (! is_string($ruta) || ! is_readable($ruta)) {
            throw new RuntimeException('No se puede leer el fichero CSV.');
        }

        $primeraLinea = (string) strtok((string) file_get_contents($ruta), "\n");
        $delimitador = str_contains($primeraLinea, ';') ? ';' : ',';
        $manejador = fopen($ruta, 'rb');
        if ($manejador === false) {
            throw new RuntimeException('No se puede abrir el fichero CSV.');
        }

        $cabeceras = fgetcsv($manejador, 0, $delimitador);
        if (! is_array($cabeceras)) {
            fclose($manejador);
            throw new RuntimeException('El CSV está vacío.');
        }

        $cabeceras = array_map(fn ($cabecera): string => $this->normalizar((string) $cabecera), $cabeceras);
        $faltan = array_diff(['fecha', 'nombre', 'ambito'], $cabeceras);
        if ($faltan !== []) {
            fclose($manejador);
            throw new RuntimeException('Faltan columnas obligatorias: '.implode(', ', $faltan).'.');
        }
        $filas = [];
        $errores = [];
        $numero = 1;

        while (($valores = fgetcsv($manejador, 0, $delimitador)) !== false) {
            $numero++;
            if ($this->filaVacia($valores)) {
                continue;
            }

            $fila = array_combine(
                $cabeceras,
                array_slice(array_pad($valores, count($cabeceras), ''), 0, count($cabeceras)),
            );
            if (! is_array($fila)) {
                $errores[] = 'La fila '.$numero.' no tiene el mismo número de columnas que la cabecera.';

                continue;
            }

            try {
                $filas[] = $this->normalizarFila($fila, $numero);
            } catch (RuntimeException $error) {
                $errores[] = $error->getMessage();
            }
        }

        fclose($manejador);

        return ['filas' => $filas, 'errores' => $errores];
    }

    /**
     * @param  list<array<string, mixed>>  $filas
     */
    public function importar(array $filas): int
    {
        DB::transaction(function () use ($filas): void {
            foreach ($filas as $fila) {
                $festivo = $this->festivoExistente($fila);

                $datos = [
                    'holiday_date' => $fila['fecha'],
                    'municipality' => $fila['municipio'],
                    'ambito' => $fila['ambito'],
                    'centro_id' => $fila['centro_id'],
                    'community' => $fila['community'],
                    'name' => $fila['nombre'],
                ];

                if ($festivo === null) {
                    Festivo::query()->create($datos);
                } else {
                    $festivo->update($datos);
                }
            }
        });

        return count($filas);
    }

    /**
     * @param  array<string, mixed>  $fila
     */
    private function festivoExistente(array $fila): ?Festivo
    {
        $consulta = Festivo::query()
            ->whereDate('holiday_date', $fila['fecha'])
            ->where('ambito', $fila['ambito']);

        if ($fila['ambito'] === 'comunidad') {
            return $consulta->where('community', $fila['community'])->first();
        }

        return $consulta->where('municipality', $fila['municipio'])->first();
    }

    /**
     * @param  array<string, string>  $fila
     * @return array{fecha: string, nombre: string, ambito: string, municipio: string, centro_id: ?int, community: ?string}
     */
    private function normalizarFila(array $fila, int $numero): array
    {
        $fecha = $this->fecha($fila['fecha'] ?? '');
        $nombre = trim($fila['nombre'] ?? '');
        $ambito = $this->ambito($fila['ambito'] ?? '');
        $municipio = trim($fila['municipio'] ?? '');
        $autonomia = $this->autonomiaDe($fila);

        if ($fecha === null) {
            throw new RuntimeException('La fila '.$numero.' tiene una fecha inválida.');
        }
        if ($nombre === '') {
            throw new RuntimeException('La fila '.$numero.' no tiene nombre.');
        }
        if ($ambito === 'pueblo' && $municipio === '') {
            throw new RuntimeException('La fila '.$numero.' necesita un municipio para ser local.');
        }
        if ($ambito === 'comunidad' && $autonomia === '') {
            throw new RuntimeException('La fila '.$numero.' necesita una autonomía.');
        }

        $centro = $ambito === 'pueblo' ? $this->centroDe($municipio) : null;

        if ($ambito === 'estado') {
            return [
                'fecha' => $fecha,
                'nombre' => $nombre,
                'ambito' => $ambito,
                'municipio' => '',
                'centro_id' => null,
                'community' => null,
            ];
        }

        if ($ambito === 'comunidad') {
            return [
                'fecha' => $fecha,
                'nombre' => $nombre,
                'ambito' => $ambito,
                'municipio' => '',
                'centro_id' => null,
                'community' => $autonomia,
            ];
        }

        return [
            'fecha' => $fecha,
            'nombre' => $nombre,
            'ambito' => $ambito,
            'municipio' => $centro?->municipality ?? $municipio,
            'centro_id' => $centro?->id,
            'community' => $autonomia !== '' ? $autonomia : $centro?->community,
        ];
    }

    private function autonomiaDe(array $fila): string
    {
        return trim($fila['autonomia'] ?? $fila['comunidad'] ?? '');
    }

    private function centroDe(string $municipio): ?Centro
    {
        if ($municipio === '') {
            return null;
        }

        return Centro::query()
            ->where('municipality', $municipio)
            ->orderBy('name')
            ->first();
    }

    private function fecha(string $valor): ?string
    {
        $valor = trim($valor);
        foreach (['Y-m-d', 'd/m/Y'] as $formato) {
            try {
                $fecha = Carbon::createFromFormat($formato, $valor);
                if ($fecha->format($formato) === $valor) {
                    return $fecha->toDateString();
                }
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        return null;
    }

    private function ambito(string $valor): string
    {
        return match ($this->normalizar($valor)) {
            'estado', 'nacional' => 'estado',
            'comunidad', 'autonomico', 'autonomica' => 'comunidad',
            'pueblo', 'local' => 'pueblo',
            default => throw new RuntimeException('Ámbito inválido: '.$valor.'.'),
        };
    }

    private function normalizar(string $valor): string
    {
        $normalizado = mb_strtolower(strtr(trim($valor), [
            "\xEF\xBB\xBF" => '',
            'á' => 'a',
            'é' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ú' => 'u',
            'ü' => 'u',
        ]));

        return (string) preg_replace('/\s+/', '_', $normalizado);
    }

    /**
     * @param  list<string>|false  $valores
     */
    private function filaVacia(array|false $valores): bool
    {
        return $valores === false || collect($valores)->every(fn ($valor): bool => trim((string) $valor) === '');
    }
}
