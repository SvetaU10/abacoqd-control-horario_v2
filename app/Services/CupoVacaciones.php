<?php

namespace App\Services;

use App\Exceptions\ReglaJornada;
use App\Models\AjusteSaldoVacaciones;
use App\Models\Ausencia;
use App\Models\SolicitudVacaciones;
use App\Models\User;
use App\Support\DiaFestivo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * El saldo de cada año debe ser confirmado por un responsable.
 * Solo cuentan los días laborables que no son festivo.
 */
class CupoVacaciones
{
    public function __construct(private NagerFestivos $festivos) {}

    public function restantes(User $user, int $anio): ?int
    {
        if (! $user->registraJornada()) {
            return 0;
        }

        $ajuste = AjusteSaldoVacaciones::query()->where('user_id', $user->id)->where('anio', $anio)->latest('id')->first();
        if (! $ajuste) {
            return null;
        }

        return $ajuste->saldo_deseado + $ajuste->aprobados_al_ajuste - $this->aprobados($user, $anio);
    }

    public function disponibles(User $user, int $anio): ?int
    {
        $restantes = $this->restantes($user, $anio);

        return $restantes === null ? null : max(0, $restantes - $this->pendientes($user, $anio));
    }

    /** Exige calendario confirmado para cada día que podría consumir vacaciones. */
    public function asegurarCobertura(User $user, Carbon $desde, Carbon $hasta): void
    {
        $fecha = $desde->copy()->startOfDay();
        $fin = $hasta->copy()->startOfDay();
        if ($fecha->gt($fin)) {
            throw new ReglaJornada('El periodo de vacaciones no es válido.');
        }

        while ($fecha->lte($fin)) {
            if (! $fecha->isWeekend() && ! $this->festivos->coberturaConfirmada($user->sedeEn($fecha), $fecha->year, $fecha)) {
                throw new ReglaJornada('No se pueden calcular las vacaciones: falta confirmar el calendario laboral del '.$fecha->format('d/m/Y').'.');
            }
            $fecha->addDay();
        }
    }

    /** El ajuste de saldo no debe contar días sin un calendario confirmado. */
    public function asegurarCoberturaParaSaldo(User $user, int $anio): void
    {
        $inicio = Carbon::create($anio, 1, 1)->startOfDay();
        $fin = Carbon::create($anio, 12, 31)->startOfDay();
        $aprobadas = $user->ausencias()->where('tipo', 'vacaciones')->whereYear('absence_date', $anio)->get();
        foreach ($aprobadas as $ausencia) {
            $this->asegurarCobertura($user, $ausencia->absence_date, $ausencia->absence_date);
        }
        $pendientes = $user->solicitudesVacaciones()->where('estado', SolicitudVacaciones::PENDIENTE)
            ->whereDate('starts_on', '<=', $fin->toDateString())
            ->whereDate('ends_on', '>=', $inicio->toDateString())->get();
        foreach ($pendientes as $solicitud) {
            $desde = $solicitud->starts_on->lt($inicio) ? $inicio : $solicitud->starts_on;
            $hasta = $solicitud->ends_on->gt($fin) ? $fin : $solicitud->ends_on;
            $this->asegurarCobertura($user, $desde, $hasta);
        }
    }

    /**
     * @return Collection<int, string>
     */
    public function queCuentan(User $user, Carbon $desde, Carbon $hasta): Collection
    {
        if (! $user->registraJornada()) {
            return collect();
        }

        $festivos = $this->festivosEntre($user, $desde, $hasta);
        $dias = collect();
        $cursor = $desde->copy()->startOfDay();
        $fin = $hasta->copy()->startOfDay();

        while ($cursor->lte($fin)) {
            $clave = $cursor->toDateString();
            if (! $cursor->isWeekend() && ! $festivos->has($clave)) {
                $dias->push($clave);
            }
            $cursor->addDay();
        }

        return $dias;
    }

    public function dentroDelPlazo(Carbon $hasta): bool
    {
        return $hasta->copy()->startOfDay()->lte(now()->startOfDay()->addMonths(2));
    }

    public function cabe(User $user, Carbon $desde, Carbon $hasta, ?int $excepto = null): bool
    {
        $porAnio = $this->queCuentan($user, $desde, $hasta)
            ->groupBy(fn (string $fecha) => substr($fecha, 0, 4));

        foreach ($porAnio as $anio => $dias) {
            $restantes = $this->restantes($user, (int) $anio);
            if ($restantes === null || $this->pendientes($user, (int) $anio, $excepto) + $dias->count() > $restantes) {
                return false;
            }
        }

        return true;
    }

    public function aprobados(User $user, int $anio): int
    {
        return $this->fechasAprobadas($user, $anio)->count();
    }

    public function pendientes(User $user, int $anio, ?int $excepto = null): int
    {
        return $user->solicitudesVacaciones()
            ->where('estado', SolicitudVacaciones::PENDIENTE)
            ->when($excepto, fn ($consulta) => $consulta->whereKeyNot($excepto))
            ->get()
            ->sum(fn (SolicitudVacaciones $solicitud) => $this->diasDelAnio($user, $solicitud, $anio));
    }

    /**
     * Panel de vacaciones del año que se mira. Reutiliza aprobados(), pendientes()
     * y disponibles(), así que los números coinciden con lo que valida cabe().
     * Corresponden = restantes + aprobados = saldo confirmado + aprobados al ajustar.
     * Si la persona no está en plantilla ningún día del año no hay saldo que confirmar.
     *
     * @return array{
     *     fueraDePlantilla: bool,
     *     corresponden: ?int,
     *     disfrutados: int,
     *     porDisfrutar: int,
     *     enEspera: int,
     *     sinAsignar: ?int,
     *     exceso: int,
     *     periodos: list<array{desde: Carbon, hasta: Carbon, dias: int, estado: string}>,
     *     solicitadas: list<array{solicitud: SolicitudVacaciones, dias: int}>,
     *     rechazadas: list<SolicitudVacaciones>
     * }
     */
    public function resumen(User $user, int $anio): array
    {
        $hoy = now()->toDateString();
        $festivos = $this->festivosDelAnio($user, $anio);
        $aprobadas = $this->fechasAprobadas($user, $anio, $festivos);
        $disfrutados = $aprobadas->filter(fn (string $fecha): bool => $fecha < $hoy)->count();
        $fuera = ! $this->enPlantillaAlgunDia($user, $anio);
        $restantes = $fuera ? null : $this->restantes($user, $anio);
        $enEspera = $this->pendientes($user, $anio);
        $inicio = Carbon::create($anio, 1, 1)->toDateString();
        $fin = Carbon::create($anio, 12, 31)->toDateString();
        $delAnio = fn (string $estado): Collection => $user->solicitudesVacaciones()
            ->where('estado', $estado)
            ->whereDate('starts_on', '<=', $fin)
            ->whereDate('ends_on', '>=', $inicio)
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get();

        return [
            'fueraDePlantilla' => $fuera,
            'corresponden' => $restantes === null ? null : $restantes + $aprobadas->count(),
            'disfrutados' => $disfrutados,
            'porDisfrutar' => $aprobadas->count() - $disfrutados,
            'enEspera' => $enEspera,
            'sinAsignar' => $restantes === null ? null : $this->disponibles($user, $anio),
            'exceso' => $restantes === null ? 0 : max(0, $enEspera - $restantes),
            'periodos' => $this->periodos($aprobadas, $festivos, $hoy),
            'solicitadas' => $delAnio(SolicitudVacaciones::PENDIENTE)
                ->map(fn (SolicitudVacaciones $solicitud): array => ['solicitud' => $solicitud, 'dias' => $this->diasDelAnio($user, $solicitud, $anio)])
                ->values()->all(),
            'rechazadas' => $delAnio(SolicitudVacaciones::RECHAZADA)->values()->all(),
        ];
    }

    /** Usa la misma regla que el registro diario: alta, fin de contrato, baja e interrupciones. */
    private function enPlantillaAlgunDia(User $user, int $anio): bool
    {
        if (! $user->registraJornada() || ! $user->starts_on) {
            return false;
        }

        $dia = Carbon::create($anio, 1, 1)->startOfDay();
        $fin = Carbon::create($anio, 12, 31)->startOfDay();
        while ($dia->lte($fin)) {
            if ($user->estaEnPlantillaEl($dia)) {
                return true;
            }
            $dia->addDay();
        }

        return false;
    }

    /**
     * Días aprobados que cuentan: laborables que no son festivo de la persona.
     *
     * @param  Collection<string, int>|null  $festivos
     * @return Collection<int, string>
     */
    private function fechasAprobadas(User $user, int $anio, ?Collection $festivos = null): Collection
    {
        $festivos ??= $this->festivosDelAnio($user, $anio);

        return $user->ausencias()
            ->where('tipo', 'vacaciones')
            ->whereYear('absence_date', $anio)
            ->orderBy('absence_date')
            ->get()
            ->map(fn (Ausencia $ausencia): Carbon => $ausencia->absence_date->copy()->startOfDay())
            ->filter(fn (Carbon $fecha): bool => ! $fecha->isWeekend() && ! $festivos->has($fecha->toDateString()))
            ->map(fn (Carbon $fecha): string => $fecha->toDateString())
            ->values();
    }

    /** Días que cuentan de una solicitud dentro de un año concreto. */
    private function diasDelAnio(User $user, SolicitudVacaciones $solicitud, int $anio): int
    {
        $desde = $solicitud->starts_on->copy()->startOfDay();
        $hasta = $solicitud->ends_on->copy()->startOfDay();
        $inicio = Carbon::create($anio, 1, 1)->startOfDay();
        $fin = Carbon::create($anio, 12, 31)->startOfDay();
        if ($hasta->lt($inicio) || $desde->gt($fin)) {
            return 0;
        }

        return $this->queCuentan(
            $user,
            $desde->lt($inicio) ? $inicio : $desde,
            $hasta->gt($fin) ? $fin : $hasta,
        )->count();
    }

    /**
     * Une los días aprobados separados solo por fines de semana o festivos.
     *
     * @param  Collection<int, string>  $fechas
     * @param  Collection<string, int>  $festivos
     * @return list<array{desde: Carbon, hasta: Carbon, dias: int, estado: string}>
     */
    private function periodos(Collection $fechas, Collection $festivos, string $hoy): array
    {
        $periodos = [];
        foreach ($fechas as $fecha) {
            $dia = Carbon::parse($fecha)->startOfDay();
            $ultimo = array_key_last($periodos);
            if ($ultimo !== null && $this->soloNoLaborablesEntre($periodos[$ultimo]['hasta'], $dia, $festivos)) {
                $periodos[$ultimo]['hasta'] = $dia;
                $periodos[$ultimo]['dias']++;

                continue;
            }
            $periodos[] = ['desde' => $dia, 'hasta' => $dia, 'dias' => 1];
        }

        return array_map(fn (array $periodo): array => $periodo + [
            'estado' => match (true) {
                $periodo['hasta']->toDateString() < $hoy => 'disfrutado',
                $periodo['desde']->toDateString() >= $hoy => 'por-disfrutar',
                default => 'en-curso',
            },
        ], $periodos);
    }

    /** @param  Collection<string, int>  $festivos */
    private function soloNoLaborablesEntre(Carbon $anterior, Carbon $siguiente, Collection $festivos): bool
    {
        $cursor = $anterior->copy()->addDay();
        while ($cursor->lt($siguiente)) {
            if (! $cursor->isWeekend() && ! $festivos->has($cursor->toDateString())) {
                return false;
            }
            $cursor->addDay();
        }

        return true;
    }

    /** @return Collection<string, int> */
    private function festivosDelAnio(User $user, int $anio): Collection
    {
        return $this->festivos->dePersona($user, $anio)
            ->map(fn (DiaFestivo $dia) => $dia->holiday_date->toDateString())
            ->flip();
    }

    /**
     * @return Collection<string, true>
     */
    private function festivosEntre(User $user, Carbon $desde, Carbon $hasta): Collection
    {
        $claves = collect();
        for ($anio = $desde->year; $anio <= $hasta->year; $anio++) {
            foreach ($this->festivos->dePersona($user, $anio) as $dia) {
                $claves->put($dia->holiday_date->toDateString(), true);
            }
        }

        return $claves;
    }
}
