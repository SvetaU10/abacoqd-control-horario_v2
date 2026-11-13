<?php

namespace App\Services;

use App\Models\Centro;
use App\Models\Festivo;
use App\Models\ImportacionCalendario;
use App\Models\VigenciaCalendario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class VigenciasCalendario
{
    public function activar(ImportacionCalendario $importacion): void
    {
        if (! $importacion->exists || ! $importacion->activado_at
            || ! in_array($importacion->estado, ['cargado', 'actualizado', 'sin_cambios'], true)) {
            throw new InvalidArgumentException('Solo se puede aplicar una importación confirmada y guardada.');
        }
        if (CalendarioMadridOficial::esOficial($importacion)) {
            $oficial = app(CalendarioMadridOficial::class)->snapshot($importacion->anio);
            if ($importacion->municipio_ine !== '28079' || $importacion->comunidad_codigo !== 'ES-MD'
                || $importacion->festivos !== $oficial['festivos'] || $importacion->fuentes !== $oficial['fuentes']
                || $importacion->hash !== $oficial['hash']) {
                throw new InvalidArgumentException('No se puede activar una copia oficial de Madrid incompleta o distinta de la revisada.');
            }
        }
        $desde = $importacion->vigente_desde?->copy()->startOfDay()
            ?? Carbon::create($importacion->anio, 1, 1)->startOfDay();
        if ($desde->year !== $importacion->anio) {
            throw new InvalidArgumentException('La fecha de aplicación debe pertenecer al año del calendario.');
        }

        DB::transaction(function () use ($importacion, $desde): void {
            $centro = Centro::query()->whereKey($importacion->centro_id)->lockForUpdate()->firstOrFail();
            $this->asegurarLegado($centro, $importacion->anio);
            $hasta = $desde->copy()->endOfYear()->startOfDay();
            $afectadas = VigenciaCalendario::query()->where('centro_id', $centro->id)
                ->where('anio', $importacion->anio)->whereNull('cancelado_at')
                ->whereDate('hasta', '>=', $desde->toDateString())->lockForUpdate()->get();
            if ($afectadas->count() === 1 && $afectadas->first()->importacion_id === $importacion->id
                && $afectadas->first()->desde->isSameDay($desde) && $afectadas->first()->hasta->isSameDay($hasta)) {
                return;
            }
            $this->recortar($afectadas, $desde);
            VigenciaCalendario::query()->create([
                'centro_id' => $centro->id, 'anio' => $importacion->anio, 'importacion_id' => $importacion->id,
                'desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString(),
            ]);
        }, 3);
    }

    public function asegurarLegado(Centro $centro, int $anio): void
    {
        if (VigenciaCalendario::query()->where('centro_id', $centro->id)->where('anio', $anio)->exists()) {
            return;
        }
        $importacion = ImportacionCalendario::query()->where('clave_activa', $centro->id.':'.$anio)->first();
        $importacion ??= $this->contextoManual($centro, $anio);
        if ($importacion) {
            $tramo = $this->tramoLegado($importacion);
            VigenciaCalendario::query()->create($tramo->getAttributes());
        }
    }

    public function conservarManuales(Centro $centro): void
    {
        DB::transaction(function () use ($centro): void {
            $anterior = Centro::query()->whereKey($centro->id)->lockForUpdate()->firstOrFail();
            $anios = Festivo::query()->where(function ($consulta) use ($anterior): void {
                $consulta->where('centro_id', $anterior->id)->orWhereNull('centro_id');
            })->get()->pluck('holiday_date')->map(fn (Carbon $fecha): int => $fecha->year)->unique();
            foreach ($anios as $anio) {
                $this->asegurarLegado($anterior, $anio);
            }
        }, 3);
    }

    private function contextoManual(Centro $centro, int $anio): ?ImportacionCalendario
    {
        $comunidades = match ($centro->community) {
            'Madrid', 'Comunidad de Madrid' => ['Madrid', 'Comunidad de Madrid'],
            'Valencia', 'Comunitat Valenciana', 'Comunidad Valenciana' => ['Valencia', 'Comunitat Valenciana', 'Comunidad Valenciana'],
            default => [$centro->community],
        };
        $dias = Festivo::query()->whereYear('holiday_date', $anio)
            ->where(function ($consulta) use ($centro): void {
                $consulta->where('centro_id', $centro->id)->orWhereNull('centro_id');
            })->orderBy('holiday_date')->get()
            ->filter(fn (Festivo $dia): bool => match ($dia->ambito) {
                'estado' => true,
                'comunidad' => in_array($dia->community, $comunidades, true),
                'pueblo' => $dia->municipality === $centro->municipality,
                null, '' => $dia->municipality === '' || $dia->municipality === $centro->municipality,
                default => false,
            })
            ->map(fn (Festivo $dia): array => [
                'date' => $dia->holiday_date->toDateString(), 'name' => $dia->name,
                'ambito' => $dia->ambito ?: 'pueblo', 'fuente' => 'Festivos manuales registrados', 'url' => '',
            ])->values()->all();
        if ($dias === []) {
            return null;
        }

        return ImportacionCalendario::query()->create([
            'centro_id' => $centro->id, 'anio' => $anio, 'municipio_ine' => $centro->municipio_ine ?? '00000',
            'municipio' => $centro->municipality, 'provincia' => $centro->province ?? '',
            'comunidad' => $centro->community, 'comunidad_codigo' => $centro->comunidad_codigo ?? '',
            'estado' => 'contexto_manual', 'mensaje' => 'Contexto territorial de los festivos manuales anteriores. No confirma la cobertura completa del año.',
            'festivos' => $dias, 'fuentes' => [['nombre' => 'Festivos manuales registrados', 'url' => '']],
            'hash' => hash('sha256', json_encode([$centro->id, $anio, $centro->municipality, $centro->community, $dias], JSON_THROW_ON_ERROR)),
            'consultado_at' => now(), 'activado_at' => now(), 'vigente_desde' => sprintf('%04d-01-01', $anio),
        ]);
    }

    public function paraFecha(Centro $centro, Carbon $fecha): ?ImportacionCalendario
    {
        return $this->delAnio($centro, $fecha->year)
            ->first(fn (VigenciaCalendario $tramo): bool => $tramo->desde->lte($fecha->copy()->startOfDay())
                && $tramo->hasta->gte($fecha->copy()->startOfDay()))?->importacion;
    }

    /** @return Collection<int,VigenciaCalendario> */
    public function delAnio(Centro $centro, int $anio): Collection
    {
        $consulta = VigenciaCalendario::query()->where('centro_id', $centro->id)->where('anio', $anio);
        $tramos = (clone $consulta)->whereNull('cancelado_at')->with('importacion')->orderBy('desde')->orderBy('id')->get();
        if ($tramos->isNotEmpty() || $consulta->exists()) {
            return $tramos;
        }
        $legado = ImportacionCalendario::query()->where('clave_activa', $centro->id.':'.$anio)->first();

        return $legado ? collect([$this->tramoLegado($legado)]) : collect();
    }

    public function invalidar(Centro $centro, Carbon $desde): void
    {
        $desde = $desde->copy()->startOfDay();
        DB::transaction(function () use ($centro, $desde): void {
            Centro::query()->whereKey($centro->id)->lockForUpdate()->firstOrFail();
            $anios = ImportacionCalendario::query()->where('centro_id', $centro->id)->where('anio', '>=', $desde->year)->pluck('anio')
                ->concat(VigenciaCalendario::query()->where('centro_id', $centro->id)->where('anio', '>=', $desde->year)->pluck('anio'))->unique();
            foreach ($anios as $anio) {
                $this->asegurarLegado($centro, (int) $anio);
            }
            $afectadas = VigenciaCalendario::query()->where('centro_id', $centro->id)->whereNull('cancelado_at')
                ->whereDate('hasta', '>=', $desde->toDateString())->lockForUpdate()->get();
            $this->recortar($afectadas, $desde);
        }, 3);
    }

    /** @param Collection<int,VigenciaCalendario> $tramos */
    private function recortar(Collection $tramos, Carbon $desde): void
    {
        foreach ($tramos as $tramo) {
            $tramo->update(['cancelado_at' => now()]);
            if ($tramo->desde->lt($desde)) {
                VigenciaCalendario::query()->create([
                    'centro_id' => $tramo->centro_id, 'anio' => $tramo->anio, 'importacion_id' => $tramo->importacion_id,
                    'desde' => $tramo->desde->toDateString(), 'hasta' => $desde->copy()->subDay()->toDateString(),
                ]);
            }
        }
    }

    private function tramoLegado(ImportacionCalendario $importacion): VigenciaCalendario
    {
        $tramo = new VigenciaCalendario([
            'centro_id' => $importacion->centro_id, 'anio' => $importacion->anio, 'importacion_id' => $importacion->id,
            'desde' => ($importacion->vigente_desde ?? Carbon::create($importacion->anio, 1, 1))->toDateString(),
            'hasta' => sprintf('%04d-12-31', $importacion->anio),
        ]);
        $tramo->setRelation('importacion', $importacion);

        return $tramo;
    }
}
