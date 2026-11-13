<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class RejillaCalendario
{
    /**
     * @param  Collection<int, DiaFestivo>  $festivos
     * @return list<array{nombre: string, anio: int, dias: list<array<string, mixed>|null>}>
     */
    public static function meses(int $anio, Collection $festivos, ?string $desde = null, ?string $hasta = null, bool $conPeriodo = false): array
    {
        [$inicioRango, $finRango] = self::rangoVisible($anio, $desde, $hasta, $conPeriodo);
        $porDia = $festivos->keyBy(fn (DiaFestivo $festivo) => $festivo->holiday_date->toDateString());
        $hoy = now()->toDateString();
        $nombres = [
            1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
            5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
            9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
        ];
        $meses = [];
        $cursor = $inicioRango->copy();

        while ($cursor->lte($finRango)) {
            $inicio = $cursor->copy()->startOfMonth();
            $dias = array_fill(0, $inicio->dayOfWeekIso - 1, null);

            for ($dia = 1; $dia <= $inicio->daysInMonth; $dia++) {
                $fecha = $inicio->copy()->day($dia)->toDateString();
                $festivo = $porDia->get($fecha);
                $finde = Carbon::parse($fecha)->isWeekend();
                $enPeriodo = $conPeriodo
                    && ($desde === null || $fecha >= $desde)
                    && ($hasta === null || $fecha <= $hasta);
                $dias[] = [
                    'numero' => $dia,
                    'hoy' => $fecha === $hoy,
                    'finde' => $finde,
                    'festivo' => $festivo,
                    'laborable' => ! $finde && $festivo === null,
                    'en_periodo' => $enPeriodo,
                ];
            }

            $meses[] = [
                'nombre' => $nombres[$inicio->month],
                'anio' => $inicio->year,
                'dias' => $dias,
            ];
            $cursor->addMonth();
        }

        return $meses;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private static function rangoVisible(int $anio, ?string $desde, ?string $hasta, bool $conPeriodo): array
    {
        if ($conPeriodo && $desde && $hasta) {
            return [
                Carbon::parse($desde)->startOfMonth(),
                Carbon::parse($hasta)->startOfMonth(),
            ];
        }

        $inicio = Carbon::create($anio, 1, 1)->startOfMonth();
        $fin = Carbon::create($anio, 12, 1)->startOfMonth();

        if ($conPeriodo && $desde && Carbon::parse($desde)->year === $anio) {
            $inicio = Carbon::parse($desde)->startOfMonth();
        }

        return [$inicio, $fin];
    }
}
