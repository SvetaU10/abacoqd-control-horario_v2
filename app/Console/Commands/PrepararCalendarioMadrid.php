<?php

namespace App\Console\Commands;

use App\Models\Centro;
use App\Models\User;
use App\Services\CalendarioMadridOficial;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

#[Signature('abaco:preparar-calendario-madrid {--centro= : ID obligatorio del centro de Madrid capital} {--autor= : ID obligatorio del jefe o técnico activo responsable} {--desde= : Fecha obligatoria de inicio, YYYY-MM-DD} {--hasta-anio=2027 : Último año que se prepara, 2026 o 2027}')]
#[Description('Prepara los calendarios oficiales locales revisados de Madrid capital sin red ni sustituciones de datos existentes')]
class PrepararCalendarioMadrid extends Command
{
    public function handle(CalendarioMadridOficial $calendario): int
    {
        $centroId = filter_var($this->option('centro'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $autorId = filter_var($this->option('autor'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $hasta = filter_var($this->option('hasta-anio'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 2026, 'max_range' => 2027]]);
        $centro = $centroId === false ? null : Centro::query()->find($centroId);
        $autor = $autorId === false ? null : User::query()->find($autorId);
        $desde = $this->option('desde');
        if (! $centro || ! $autor || ! is_string($desde) || $desde === '' || $hasta === false) {
            $this->error('Indica --centro y --autor existentes, --desde=YYYY-MM-DD y --hasta-anio=2026 o 2027.');

            return self::INVALID;
        }

        try {
            $importaciones = $calendario->preparar($centro, $autor, $desde, $hasta);
        } catch (InvalidArgumentException $error) {
            $this->error($error->getMessage());

            return self::INVALID;
        } catch (RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        foreach ($importaciones as $anio => $importacion) {
            $this->info('Madrid · '.$anio.': calendario oficial completo, 14 fechas; vigente desde '.$importacion->vigente_desde->toDateString().'.');
        }
        $this->info('Preparación comprobada. No se han consultado APIs ni creado jornadas. Repetir los mismos parámetros no duplica datos.');

        return self::SUCCESS;
    }
}
