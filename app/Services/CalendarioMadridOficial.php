<?php

namespace App\Services;

use App\Models\Centro;
use App\Models\Festivo;
use App\Models\ImportacionCalendario;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

class CalendarioMadridOficial
{
    private const TIPO = 'madrid_oficial_snapshot';

    // Huellas del JSON canónico revisado: no dependen de CRLF/LF ni de la indentación.
    private const HASHES = [
        2026 => 'd1ab916873f59493d1090706c21def41a340f7c8d2b4dc8e5eb7abe056200e6d',
        2027 => 'ff2c2820337bc28e282c0e199eea1f41104cf4472bc8b88a27d609af71592ded',
    ];

    public function __construct(private VigenciasCalendario $vigencias) {}

    /**
     * Carga explícita para una instalación nueva; nunca reemplaza una configuración distinta.
     *
     * @return array<int,ImportacionCalendario>
     */
    public function preparar(Centro $centro, User $autor, string $desde, int $hastaAnio = 2027): array
    {
        if (! preg_match('/^(2026|2027)-(\d{2})-(\d{2})$/D', $desde, $partes)
            || ! checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1])
            || $hastaAnio < (int) $partes[1] || $hastaAnio > 2027) {
            throw new InvalidArgumentException('Indica una fecha real de 2026 o 2027 y un año final entre esa fecha y 2027.');
        }
        $snapshots = [];
        foreach (range((int) $partes[1], $hastaAnio) as $anio) {
            $snapshots[$anio] = $this->snapshot($anio);
        }

        return DB::transaction(function () use ($centro, $autor, $desde, $snapshots): array {
            $centro = Centro::query()->whereKey($centro->id)->lockForUpdate()->firstOrFail();
            $autor = User::query()->whereKey($autor->id)->lockForUpdate()->firstOrFail();
            if (! $autor->active || (! $autor->esJefa() && ! $autor->esTecnico())) {
                throw new InvalidArgumentException('La preparación requiere un responsable activo con rol de jefe o técnico.');
            }
            if ($centro->municipio_ine !== '28079' || $centro->municipality !== 'Madrid'
                || ! in_array($centro->community, ['Madrid', 'Comunidad de Madrid'], true)
                || $centro->comunidad_codigo !== 'ES-MD') {
                throw new InvalidArgumentException('El centro debe estar configurado como Madrid capital, INE 28079 y comunidad ES-MD.');
            }

            $resultado = [];
            foreach ($snapshots as $anio => $snapshot) {
                $efecto = (int) substr($desde, 0, 4) === $anio ? $desde : $anio.'-01-01';
                $this->rechazarAjustesManuales($centro, $anio, $efecto);
                $activa = ImportacionCalendario::query()->where('clave_activa', $centro->id.':'.$anio)->lockForUpdate()->first();
                $tramos = $this->vigencias->delAnio($centro, $anio);
                if ($activa !== null || $tramos->isNotEmpty()) {
                    if ($activa !== null && self::esOficial($activa) && $activa->hash === $snapshot['hash']
                        && $activa->festivos === $snapshot['festivos']
                        && $activa->fuentes === $snapshot['fuentes']
                        && $activa->vigente_desde?->toDateString() === $efecto
                        && in_array($activa->estado, ['cargado', 'actualizado', 'sin_cambios'], true)
                        && $activa->activado_at !== null && $tramos->count() === 1
                        && $tramos->first()->importacion_id === $activa->id
                        && $tramos->first()->desde->toDateString() === $efecto
                        && $tramos->first()->hasta->toDateString() === $anio.'-12-31') {
                        $resultado[$anio] = $activa;

                        continue;
                    }

                    throw new InvalidArgumentException('El centro ya tiene un calendario o una vigencia diferente en '.$anio.'. Se conserva: su sustitución requiere una revisión explícita.');
                }

                $importacion = ImportacionCalendario::query()->create([
                    'centro_id' => $centro->id, 'anio' => $anio, 'municipio_ine' => '28079',
                    'municipio' => 'Madrid', 'provincia' => 'Madrid', 'comunidad' => 'Comunidad de Madrid',
                    'comunidad_codigo' => 'ES-MD', 'estado' => 'cargado',
                    'mensaje' => 'Calendario oficial completo de Madrid capital, comprobado el '.$snapshot['verificado_el'].'. Vigente desde '.$efecto.'. Copia local; no requiere consultar proveedores.',
                    'festivos' => $snapshot['festivos'], 'fuentes' => $snapshot['fuentes'], 'hash' => $snapshot['hash'],
                    'clave_activa' => $centro->id.':'.$anio, 'vigente_desde' => $efecto,
                    'consultado_at' => now(), 'activado_at' => now(),
                    'consultado_por' => $autor->id, 'activado_por' => $autor->id,
                ]);
                $this->vigencias->activar($importacion);
                $resultado[$anio] = $importacion;
            }

            return $resultado;
        }, 3);
    }

    /** @return array<string,mixed> */
    public function snapshot(int $anio): array
    {
        if (! isset(self::HASHES[$anio])) {
            throw new InvalidArgumentException('Solo hay calendarios oficiales revisados de Madrid capital para 2026 y 2027.');
        }
        $datos = $this->leerSnapshot($anio);
        $dias = $datos['festivos'] ?? null;
        if (($datos['version'] ?? null) !== 1 || ($datos['anio'] ?? null) !== $anio
            || ($datos['municipio_ine'] ?? null) !== '28079' || ($datos['comunidad_codigo'] ?? null) !== 'ES-MD'
            || ! is_array($dias) || ! array_is_list($dias) || count($dias) !== 14) {
            throw new RuntimeException('El calendario oficial local no acredita catorce fiestas de Madrid capital para '.$anio.'.');
        }
        $fechas = [];
        $locales = [];
        foreach ($dias as $dia) {
            if (! is_array($dia) || ! is_string($dia['date'] ?? null)
                || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $dia['date'], $fecha)
                || (int) $fecha[1] !== $anio || ! checkdate((int) $fecha[2], (int) $fecha[3], $anio)
                || ! is_string($dia['name'] ?? null) || trim($dia['name']) === ''
                || ! in_array($dia['ambito'] ?? null, ['estado', 'comunidad', 'pueblo'], true)
                || isset($fechas[$dia['date']])) {
                throw new RuntimeException('El calendario oficial local contiene una fecha, ámbito o nombre inválido, o una fecha duplicada.');
            }
            $fechas[$dia['date']] = true;
            if ($dia['ambito'] === 'pueblo') {
                $locales[] = $dia['date'];
            }
        }
        sort($locales);
        if ($locales !== [$anio.'-05-15', $anio.'-11-09']) {
            throw new RuntimeException('El calendario oficial local no acredita las dos fiestas municipales revisadas.');
        }
        $hash = hash('sha256', json_encode($datos, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if (! hash_equals(self::HASHES[$anio], $hash)) {
            throw new RuntimeException('La huella del calendario oficial local no coincide con la copia revisada. No se activa una copia modificada.');
        }
        // La fuente municipal acredita el conjunto y sus locales; el BOE clasifica los generales.
        $festivos = array_map(function (array $dia) use ($datos): array {
            $fuente = $datos['fuentes'][$dia['ambito'] === 'pueblo' ? 0 : 1];

            return $dia + ['fuente' => $fuente['nombre'], 'url' => $fuente['url']];
        }, $dias);
        $fuentes = $datos['fuentes'];
        $fuentes[0] += ['tipo' => self::TIPO, 'sha256_snapshot' => $hash, 'verificado_el' => $datos['verificado_el']];

        return [
            'festivos' => $festivos, 'fuentes' => $fuentes, 'verificado_el' => $datos['verificado_el'],
            'hash' => hash('sha256', json_encode(['28079', $anio, $festivos], JSON_THROW_ON_ERROR)),
        ];
    }

    public static function esOficial(ImportacionCalendario $importacion): bool
    {
        return collect($importacion->fuentes ?? [])->contains(fn ($fuente): bool => is_array($fuente)
            && ($fuente['tipo'] ?? null) === self::TIPO);
    }

    /** @return array<string,mixed> */
    protected function leerSnapshot(int $anio): array
    {
        $ruta = resource_path('data/calendarios/madrid-28079-'.$anio.'.json');
        if (! is_file($ruta) || ! is_readable($ruta)) {
            throw new RuntimeException('No se encuentra la copia local del calendario oficial de Madrid '.$anio.'.');
        }
        try {
            $datos = json_decode(file_get_contents($ruta), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('La copia local del calendario oficial no tiene un JSON válido.', previous: $error);
        }
        if (! is_array($datos)) {
            throw new RuntimeException('La copia local del calendario oficial no tiene un formato válido.');
        }

        return $datos;
    }

    private function rechazarAjustesManuales(Centro $centro, int $anio, string $desde): void
    {
        $ajustes = Festivo::query()->whereYear('holiday_date', $anio)->whereDate('holiday_date', '>=', $desde)
            ->where(fn ($consulta) => $consulta->where('centro_id', $centro->id)->orWhereNull('centro_id'))->get();
        $aplicable = $ajustes->contains(fn (Festivo $dia): bool => match ($dia->ambito) {
            'estado' => true,
            'comunidad' => in_array($dia->community, ['Madrid', 'Comunidad de Madrid'], true),
            'pueblo' => $dia->municipality === 'Madrid',
            null, '' => $dia->municipality === '' || $dia->municipality === 'Madrid',
            default => false,
        });
        if ($aplicable) {
            throw new InvalidArgumentException('Hay festivos manuales aplicables en '.$anio.'. Se conservan; revisa su compatibilidad antes de preparar el calendario oficial.');
        }
    }
}
