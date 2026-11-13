<?php

namespace App\Services;

use App\Models\CargaAdministrativaDia;
use App\Models\Incidencia;
use App\Models\Jornada;
use App\Models\Tramo;
use App\Models\User;
use App\Support\Tiempo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Copia del registro de una persona en CSV. La comparten quien consulta
 * su propio registro y quien lo consulta con motivo.
 */
class RegistroCsv
{
    /** @var list<string> */
    public const COLUMNAS = ['Fecha', 'Inicio', 'Fin', 'Total', 'Estado', 'Incidencia', 'Origen', 'Horario estimado'];

    public function __construct(private JornadaService $jornada, private CargaAdministrativa $carga) {}

    /**
     * La misma fila que pinta la tabla de Mi registro.
     *
     * @return array{Fecha: string, Inicio: string, Fin: string, Total: string, Estado: string, Incidencia: string}
     */
    public function fila(User $persona, Jornada $jornada): array
    {
        $jornada->loadMissing('tramos');
        $primero = $jornada->tramos
            ->where('tipo', Tramo::TRABAJO)
            ->sortBy('started_at')
            ->first();
        $ultimo = $jornada->tramos
            ->filter(fn (Tramo $tramo) => $tramo->ended_at !== null)
            ->sortBy('ended_at')
            ->last();
        $incidencia = $this->incidenciaEnDia($persona, $jornada)
            || $jornada->tramos->contains(fn (Tramo $tramo) => $tramo->situacion === 'incidencia');

        return [
            'Fecha' => Tiempo::fecha($jornada->work_date),
            'Inicio' => $primero?->started_at ? Tiempo::hora($primero->started_at) : '',
            'Fin' => $jornada->estaCerrada() && $ultimo?->ended_at ? Tiempo::hora($ultimo->ended_at) : '',
            'Total' => Tiempo::texto($this->jornada->minutosRegistrados($persona, $jornada)),
            'Estado' => $this->estado($jornada),
            'Incidencia' => $incidencia ? 'Sí' : 'No',
            'Origen' => 'Fichaje real',
            'Horario estimado' => '',
        ];
    }

    public function etiquetaCarga(CargaAdministrativaDia $dia): string
    {
        return $this->carga->esPrevision($dia) ? 'Previsión administrativa' : 'Carga administrativa';
    }

    public function horarioCarga(CargaAdministrativaDia $dia): string
    {
        $texto = substr($dia->morning_start, 0, 5).'–'.substr($dia->morning_end, 0, 5);
        if ($dia->afternoon_start !== $dia->afternoon_end) {
            $texto .= ' · '.substr($dia->afternoon_start, 0, 5).'–'.substr($dia->afternoon_end, 0, 5);
        }

        return $texto;
    }

    /** @return array<string, string> */
    public function filaCarga(CargaAdministrativaDia $dia): array
    {
        return [
            'Fecha' => Tiempo::fecha($dia->fecha), 'Inicio' => '', 'Fin' => '',
            'Total' => Tiempo::texto($dia->minutos), 'Estado' => $this->etiquetaCarga($dia),
            'Incidencia' => '', 'Origen' => $this->etiquetaCarga($dia),
            'Horario estimado' => $this->horarioCarga($dia),
        ];
    }

    /** @return Collection<int, array{tipo:string,fecha:Carbon,registro:Jornada|CargaAdministrativaDia}> */
    public function combinadas(User $persona, string $desde, string $hasta, string $estado, string $incidencia): Collection
    {
        $reales = in_array($estado, ['carga', 'prevision'], true)
            ? collect()
            : $this->consulta($persona, $desde, $hasta, $estado, $incidencia)->get()
                ->map(fn (Jornada $jornada): array => ['tipo' => 'real', 'fecha' => $jornada->work_date, 'registro' => $jornada]);
        $cargas = collect();
        if ($incidencia === '' && in_array($estado, ['', 'carga', 'prevision'], true)) {
            $dias = $this->carga->resumen($persona, $desde !== '' ? $desde : '1900-01-01', $hasta !== '' ? $hasta : '9999-12-31')['dias'];
            $cargas = $dias->filter(fn (CargaAdministrativaDia $dia): bool => $estado === ''
                || ($estado === 'carga' && ! $this->carga->esPrevision($dia))
                || ($estado === 'prevision' && $this->carga->esPrevision($dia)))
                ->map(fn (CargaAdministrativaDia $dia): array => ['tipo' => 'carga', 'fecha' => $dia->fecha, 'registro' => $dia]);
        }

        return $reales->concat($cargas)->sortByDesc(fn (array $fila): string => $fila['fecha']->toDateString())->values();
    }

    /**
     * Datos de una fila de la lista de Mi registro.
     *
     * @return array{fecha: string, tramos: string, barras: list<array{left: float, width: float}>, total: string, estado: string, clase: string, incidencia: bool}
     */
    public function presentacion(User $persona, Jornada $jornada): array
    {
        $jornada->loadMissing('tramos');
        $fecha = $jornada->work_date->copy()->locale('es');
        $trabajos = $jornada->tramos->where('tipo', Tramo::TRABAJO)->sortBy('started_at')->values();
        $textos = [];
        $barras = [];
        foreach ($trabajos as $tramo) {
            $fin = $this->finDePresentacion($tramo, $jornada);
            if ($fin === null) {
                $textos[] = Tiempo::hora($tramo->started_at).' – sin salida';

                continue;
            }
            $textos[] = Tiempo::hora($tramo->started_at).' – '.Tiempo::hora($fin);
            $trozo = $this->eje($tramo->started_at, $fin);
            if ($trozo) {
                $barras[] = $trozo;
            }
        }
        $abierta = $jornada->tramoAbierto() !== null;
        $retraso = $this->jornada->diasDeRetraso($jornada);

        return [
            'fecha' => ucfirst($fecha->isoFormat('dddd')).', '.$fecha->day.' '.rtrim($fecha->isoFormat('MMM'), '.'),
            'tramos' => $textos === [] ? '—' : implode(' · ', $textos),
            'barras' => $barras,
            'total' => Tiempo::corto($this->jornada->minutosRegistrados($persona, $jornada)),
            'estado' => $this->estadoTexto($jornada, $abierta, $retraso),
            'clase' => $abierta ? 'abierta' : (($jornada->closed_late || $retraso > 0) ? 'tardia' : 'cerrada'),
            'incidencia' => $this->incidenciaEnDia($persona, $jornada)
                || $jornada->tramos->contains(fn (Tramo $tramo) => $tramo->situacion === 'incidencia'),
        ];
    }

    /**
     * @return Builder<Jornada>
     */
    public function consulta(User $persona, string $desde, string $hasta, string $estado, string $incidencia): Builder
    {
        $incidenciaDias = $this->diasConIncidencia($persona, $desde, $hasta);
        $fueraDePlazoDias = $this->diasFueraDePlazo($persona, $desde, $hasta);

        return Jornada::query()
            ->with('tramos')
            ->where('user_id', $persona->id)
            ->when($desde !== '', fn ($q) => $q->whereDate('work_date', '>=', $desde))
            ->when($hasta !== '', fn ($q) => $q->whereDate('work_date', '<=', $hasta))
            ->when($estado === 'pendiente', fn ($q) => $q->whereHas('tramos', fn ($tramo) => $tramo->whereNull('ended_at')))
            ->when($estado === 'tardia', fn ($q) => $q
                ->where('closed_late', true)
                ->whereDoesntHave('tramos', fn ($tramo) => $tramo->whereNull('ended_at')))
            ->when($estado === 'fuera_plazo', fn ($q) => $q
                ->where('closed_late', false)
                ->whereDoesntHave('tramos', fn ($tramo) => $tramo->whereNull('ended_at'))
                ->whereIn('work_date', $fueraDePlazoDias))
            ->when($estado === 'cerrada', fn ($q) => $q
                ->where('closed_late', false)
                ->whereDoesntHave('tramos', fn ($tramo) => $tramo->whereNull('ended_at'))
                ->whereNotIn('work_date', $fueraDePlazoDias))
            ->when($incidencia === 'si', fn ($q) => $q->where(function ($q) use ($incidenciaDias) {
                $q->whereHas('tramos', fn ($tramo) => $tramo->where('situacion', 'incidencia'))
                    ->orWhereIn('work_date', $incidenciaDias);
            }))
            ->when($incidencia === 'no', fn ($q) => $q
                ->whereDoesntHave('tramos', fn ($tramo) => $tramo->where('situacion', 'incidencia'))
                ->whereNotIn('work_date', $incidenciaDias))
            ->orderByDesc('work_date');
    }

    public function descargar(User $persona, ?string $desde = null, ?string $hasta = null, string $estado = '', string $incidencia = ''): StreamedResponse
    {
        $desde = is_string($desde) ? $desde : '';
        $hasta = is_string($hasta) ? $hasta : '';
        $jornadas = $this->combinadas($persona, $desde, $hasta, $estado, $incidencia);

        return $this->archivo($persona, $jornadas);
    }

    public function descargarVacio(User $persona): StreamedResponse
    {
        return $this->archivo($persona, collect());
    }

    private function archivo(User $persona, iterable $jornadas): StreamedResponse
    {
        $nombre = 'registro-'.$persona->id.'.csv';

        return response()->streamDownload(function () use ($jornadas, $persona) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, self::COLUMNAS, ';');

            foreach ($jornadas as $registro) {
                $fila = $registro['tipo'] === 'carga'
                    ? $this->filaCarga($registro['registro'])
                    : $this->fila($persona, $registro['registro']);
                fputcsv($salida, array_values($fila), ';');
            }

            fclose($salida);
        }, $nombre, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Un tramo abierto de un día pasado no usa la hora actual: no se sabe
     * cuándo terminó.
     */
    private function finDePresentacion(Tramo $tramo, Jornada $jornada): ?Carbon
    {
        if ($tramo->ended_at) {
            return $tramo->ended_at;
        }

        if ($jornada->work_date->isSameDay(now())) {
            return now();
        }

        return null;
    }

    private function estado(Jornada $jornada): string
    {
        if ($jornada->tramoAbierto()) {
            return 'Pendiente de cerrar';
        }

        if ($jornada->closed_late) {
            return 'Cerrada al día siguiente';
        }

        $retraso = $this->jornada->diasDeRetraso($jornada);
        if ($retraso > 0) {
            return 'Fichado fuera de plazo';
        }

        return 'Cerrada';
    }

    private function estadoTexto(Jornada $jornada, bool $abierta, int $retraso): string
    {
        if ($abierta) {
            return 'Abierta';
        }
        if ($jornada->closed_late) {
            return 'Cierre al día siguiente';
        }
        if ($retraso > 0) {
            return 'Fichado fuera de plazo';
        }

        return 'Cerrada';
    }

    private function incidenciaEnDia(User $persona, Jornada $jornada): bool
    {
        return Incidencia::query()
            ->where('user_id', $persona->id)
            ->whereDate('created_at', $jornada->work_date->toDateString())
            ->exists();
    }

    /**
     * @return list<string>
     */
    private function diasConIncidencia(User $persona, string $desde, string $hasta): array
    {
        return Incidencia::query()
            ->where('user_id', $persona->id)
            ->when($desde !== '', fn ($q) => $q->whereDate('created_at', '>=', $desde))
            ->when($hasta !== '', fn ($q) => $q->whereDate('created_at', '<=', $hasta))
            ->get(['created_at'])
            ->map(fn (Incidencia $incidencia) => $incidencia->created_at->toDateString())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function diasFueraDePlazo(User $persona, string $desde, string $hasta): array
    {
        return Jornada::query()
            ->with('tramos')
            ->where('user_id', $persona->id)
            ->when($desde !== '', fn ($q) => $q->whereDate('work_date', '>=', $desde))
            ->when($hasta !== '', fn ($q) => $q->whereDate('work_date', '<=', $hasta))
            ->get()
            ->filter(fn (Jornada $jornada) => ! $jornada->tramoAbierto()
                && ! $jornada->closed_late
                && $this->jornada->diasDeRetraso($jornada) > 0)
            ->map(fn (Jornada $jornada) => $jornada->work_date->toDateString())
            ->values()
            ->all();
    }

    /**
     * @return array{left: float, width: float}|null
     */
    private function eje(Carbon $inicio, Carbon $fin): ?array
    {
        $desde = 7 * 60;
        $hasta = 21 * 60;
        $a = max($desde, min($hasta, $this->minuto($inicio)));
        $b = max($desde, min($hasta, $this->minuto($fin)));
        if ($b <= $a) {
            return null;
        }

        return [
            'left' => round(($a - $desde) / ($hasta - $desde) * 100, 2),
            'width' => round(($b - $a) / ($hasta - $desde) * 100, 2),
        ];
    }

    private function minuto(Carbon $momento): int
    {
        $local = $momento->timezone(config('app.timezone'));

        return ((int) $local->format('H')) * 60 + (int) $local->format('i');
    }
}
