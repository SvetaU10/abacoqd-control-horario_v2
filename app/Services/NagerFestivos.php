<?php

namespace App\Services;

use App\Models\AsignacionCentro;
use App\Models\Centro;
use App\Models\Festivo;
use App\Models\User;
use App\Models\VigenciaCalendario;
use App\Support\DiaFestivo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class NagerFestivos
{
    public function __construct(private VigenciasCalendario $vigencias) {}

    /** @return Collection<int,DiaFestivo> */
    public function delAnio(Centro|string|null $centro, int $anio): Collection
    {
        $centro = $this->resolverCentro($centro);
        $tramos = $centro ? $this->vigencias->delAnio($centro, $anio) : collect();
        $importados = $tramos->flatMap(function (VigenciaCalendario $tramo): array {
            $dias = [];
            foreach ($tramo->importacion->festivos ?? [] as $dia) {
                $fecha = Carbon::parse($dia['date'])->startOfDay();
                if ($fecha->betweenIncluded($tramo->desde, $tramo->hasta)) {
                    $dias[] = new DiaFestivo($fecha, $dia['name'], $dia['ambito']);
                }
            }

            return $dias;
        });
        $manuales = Festivo::query()->whereYear('holiday_date', $anio)
            ->where(function ($consulta) use ($centro): void {
                $consulta->where('centro_id', $centro?->id ?? 0)->orWhereNull('centro_id');
            })
            ->orderBy('holiday_date')->get()
            ->filter(function (Festivo $festivo) use ($centro, $tramos): bool {
                $tramo = $tramos->first(fn (VigenciaCalendario $vigencia): bool => $festivo->holiday_date->betweenIncluded($vigencia->desde, $vigencia->hasta));
                $municipio = $tramo?->importacion->municipio ?? $centro?->municipality ?? '';
                $comunidad = $tramo?->importacion->comunidad ?? $centro?->community;
                $comunidades = match ($comunidad) {
                    'Madrid', 'Comunidad de Madrid' => ['Madrid', 'Comunidad de Madrid'],
                    'Valencia', 'Comunitat Valenciana', 'Comunidad Valenciana' => ['Valencia', 'Comunitat Valenciana', 'Comunidad Valenciana'],
                    default => [$comunidad],
                };

                return match ($festivo->ambito) {
                    'estado' => true,
                    'comunidad' => in_array($festivo->community, $comunidades, true),
                    'pueblo' => $festivo->municipality === $municipio,
                    null, '' => $festivo->municipality === '' || $festivo->municipality === $municipio,
                    default => false,
                };
            })
            ->map(fn (Festivo $festivo): DiaFestivo => new DiaFestivo($festivo->holiday_date->copy()->startOfDay(), $festivo->name, $festivo->ambito ?: 'pueblo'));

        return $importados->concat($manuales)
            ->keyBy(fn (DiaFestivo $dia): string => $dia->holiday_date->toDateString())
            ->sortBy(fn (DiaFestivo $dia): string => $dia->holiday_date->toDateString())->values();
    }

    public function coberturaConfirmada(Centro|string|null $centro, int $anio, ?Carbon $fecha = null): bool
    {
        $centro = $this->resolverCentro($centro);
        if (! $centro || ($fecha !== null && $fecha->year !== $anio)) {
            return false;
        }
        if ($fecha !== null) {
            $importacion = $this->vigencias->paraFecha($centro, $fecha);

            return $importacion !== null && in_array($importacion->estado, ['cargado', 'actualizado', 'sin_cambios'], true);
        }

        return $this->cubrenElAnio($this->vigencias->delAnio($centro, $anio), $anio);
    }

    /** @return Collection<int,DiaFestivo> */
    public function dePersona(User $persona, int $anio): Collection
    {
        $centros = $this->asignacionesDelAnio($persona, $anio)->map->centro->filter()->unique('id');

        return $centros->flatMap(fn (Centro $centro): Collection => $this->delAnio($centro, $anio)
            ->filter(fn (DiaFestivo $dia): bool => $persona->sedeEn($dia->holiday_date)?->id === $centro->id))
            ->keyBy(fn (DiaFestivo $dia): string => $dia->holiday_date->toDateString())
            ->sortBy(fn (DiaFestivo $dia): string => $dia->holiday_date->toDateString())->values();
    }

    public function coberturaPersonaConfirmada(User $persona, int $anio): bool
    {
        return $this->cubrenElAnio($this->vigenciasDePersona($persona, $anio), $anio);
    }

    /** @return Collection<int,VigenciaCalendario> */
    public function vigenciasDePersona(User $persona, int $anio): Collection
    {
        $resultado = collect();
        $porCentro = [];
        foreach ($this->asignacionesDelAnio($persona, $anio) as $asignacion) {
            if (! $asignacion->centro) {
                continue;
            }
            $porCentro[$asignacion->centro_id] ??= $this->vigencias->delAnio($asignacion->centro, $anio);
            foreach ($porCentro[$asignacion->centro_id] as $tramo) {
                $desde = $tramo->desde->copy();
                $hasta = $tramo->hasta->copy();
                if ($asignacion->desde && $asignacion->desde->gt($desde)) {
                    $desde = $asignacion->desde->copy();
                }
                if ($asignacion->hasta && $asignacion->hasta->lt($hasta)) {
                    $hasta = $asignacion->hasta->copy();
                }
                if ($desde->gt($hasta)) {
                    continue;
                }
                $copia = $tramo->replicate();
                $copia->desde = $desde;
                $copia->hasta = $hasta;
                $copia->setRelation('importacion', $tramo->importacion);
                $resultado->push($copia);
            }
        }

        return $resultado->sortBy('desde')->values();
    }

    /** @return Collection<int,AsignacionCentro> */
    private function asignacionesDelAnio(User $persona, int $anio): Collection
    {
        $persona->loadMissing('asignacionesCentro.centro');
        if ($persona->asignacionesCentro->isEmpty()) {
            if (! $persona->sede) {
                return collect();
            }
            $legado = new AsignacionCentro(['user_id' => $persona->id, 'centro_id' => $persona->centro_id]);
            $legado->setRelation('centro', $persona->sede);

            return collect([$legado]);
        }
        $inicio = Carbon::create($anio, 1, 1)->startOfDay();
        $fin = $inicio->copy()->endOfYear()->startOfDay();

        return $persona->asignacionesCentro->filter(fn (AsignacionCentro $asignacion): bool => $asignacion->cancelado_at === null
            && ($asignacion->desde === null || $asignacion->desde->lte($fin))
            && ($asignacion->hasta === null || $asignacion->hasta->gte($inicio)))->values();
    }

    /** @param Collection<int,VigenciaCalendario> $tramos */
    private function cubrenElAnio(Collection $tramos, int $anio): bool
    {
        $cursor = Carbon::create($anio, 1, 1)->startOfDay();
        $hasta = $cursor->copy()->endOfYear()->startOfDay();
        foreach ($tramos as $tramo) {
            if (! in_array($tramo->importacion->estado, ['cargado', 'actualizado', 'sin_cambios'], true)) {
                continue;
            }
            if ($tramo->desde->gt($cursor)) {
                return false;
            }
            if ($tramo->hasta->gte($cursor)) {
                $cursor = $tramo->hasta->copy()->addDay();
            }
        }

        return $cursor->gt($hasta);
    }

    public function es(Centro|string|null $centro, Carbon $fecha): bool
    {
        $clave = $fecha->toDateString();

        return $this->delAnio($centro, $fecha->year)
            ->contains(fn (DiaFestivo $dia): bool => $dia->holiday_date->toDateString() === $clave);
    }

    private function resolverCentro(Centro|string|null $centro): ?Centro
    {
        if ($centro instanceof Centro) {
            return $centro;
        }
        if ($centro === null || $centro === '') {
            return null;
        }

        return Centro::query()->where('name', $centro)->orWhereKey($centro)->first();
    }
}
