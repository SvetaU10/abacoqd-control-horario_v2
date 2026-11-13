<?php

namespace App\Console\Commands;

use App\Models\Centro;
use App\Models\ImportacionCalendario;
use App\Models\User;
use App\Services\CalendarioMunicipal;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

#[Signature('calendario:revisar {--anio= : Año que se revisará; por defecto, el actual y el siguiente} {--centro= : ID del centro que se revisará} {--autor= : ID obligatorio de el responsable activa responsable}')]
#[Description('Vuelve a consultar los calendarios configurados sin sustituir datos válidos por resultados incompletos')]
class RevisarCalendarios extends Command
{
    public function handle(CalendarioMunicipal $calendario): int
    {
        $autorId = filter_var($this->option('autor'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $autor = $autorId === false ? null : User::query()->whereKey($autorId)->where('active', true)->jefes()->first();
        if (! $autor) {
            $this->error('Indica --autor con el ID de un responsable activo de la revisión.');

            return self::INVALID;
        }

        $anios = [now()->year, now()->year + 1];
        if ($this->option('anio') !== null) {
            $anio = filter_var($this->option('anio'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]);
            if ($anio === false) {
                $this->error('El año debe ser un entero entre 2000 y 2100.');

                return self::INVALID;
            }
            $anios = [$anio];
        }

        $centroId = null;
        if ($this->option('centro') !== null) {
            $centroId = filter_var($this->option('centro'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($centroId === false || ! Centro::query()->whereKey($centroId)->exists()) {
                $this->error('Indica --centro con el ID de un centro existente.');

                return self::INVALID;
            }
        }

        $ultimas = ImportacionCalendario::query()
            ->whereIn('anio', $anios)
            ->when($centroId !== null, fn ($consulta) => $consulta->where('centro_id', $centroId))
            ->whereIn('id', ImportacionCalendario::query()->selectRaw('MAX(id)')->groupBy('centro_id', 'anio'))
            ->with('centro')
            ->orderBy('centro_id')
            ->orderBy('anio')
            ->get();
        $revisados = 0;
        $omitidos = 0;
        $errores = 0;

        foreach ($ultimas as $ultima) {
            $nombre = $ultima->centro?->name ?? 'Centro '.$ultima->centro_id;
            $contexto = $nombre.' · '.$ultima->anio;
            if ($ultima->estado === 'configuracion_modificada') {
                $this->warn($contexto.': omitido. Ha cambiado el territorio del centro; selecciona su municipio y carga el calendario desde la aplicación.');
                $omitidos++;

                continue;
            }
            if (! $ultima->centro || ! preg_match('/^\d{5}$/D', (string) $ultima->municipio_ine)) {
                $this->warn($contexto.': omitido. La configuración no tiene un municipio válido; selecciónalo desde la aplicación.');
                $omitidos++;

                continue;
            }

            try {
                $resultado = $calendario->cargar($ultima->centro, $ultima->anio, $ultima->municipio_ine, $autor, desde: $ultima->vigente_desde?->toDateString());
                $revisados++;
                $mensaje = $contexto.': '.$resultado->etiqueta().'. '.$resultado->mensaje;
                if ($resultado->estado === 'error') {
                    $this->error($mensaje);
                    $errores++;
                } else {
                    $this->line($mensaje);
                }
            } catch (InvalidArgumentException $error) {
                $this->warn($contexto.': omitido. '.$error->getMessage().' Revisa el municipio desde la aplicación.');
                $omitidos++;
            } catch (Throwable $error) {
                report($error);
                $this->error($contexto.': error técnico. '.$error->getMessage());
                $errores++;
            }
        }

        if ($ultimas->isEmpty()) {
            $this->info('No hay calendarios configurados para los centros y años seleccionados. Carga primero su municipio desde la aplicación.');
        }
        $this->info('Revisados: '.$revisados.'. Omitidos: '.$omitidos.'. Errores técnicos: '.$errores.'.');

        return $errores > 0 ? self::FAILURE : self::SUCCESS;
    }
}
