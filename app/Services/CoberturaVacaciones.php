<?php

namespace App\Services;

use App\Models\Ausencia;
use App\Models\Centro;
use App\Models\SolicitudVacaciones;
use App\Models\User;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Cobertura de una sede: plantilla menos vacaciones ya aprobadas.
 * Las solicitudes pendientes se ven, y solo restan al mirar esa fila.
 */
class CoberturaVacaciones
{
    /** @var array<string, array<int, array{ids: list<int>, nombres: list<string>}>> */
    private array $ocupacion = [];

    public function __construct(private CupoVacaciones $cupo, private NagerFestivos $festivos) {}

    /**
     * @param  Collection<int, Centro>  $centros
     * @param  Collection<int, SolicitudVacaciones>|iterable<int, SolicitudVacaciones>  $visibles
     * @return array{
     *     sedeInicial: int,
     *     sedes: list<array<string, mixed>>,
     *     solicitudes: array<int, array<string, mixed>>
     * }
     */
    public function paraGestion(Collection $centros, iterable $visibles, ?User $quienMira = null): array
    {
        $this->ocupacion = [];
        $hoy = now()->startOfDay();
        $fin = $hoy->copy()->addMonths(2);
        $visibles = $visibles instanceof Paginator ? $visibles->getCollection() : collect($visibles);
        $personas = User::query()
            ->where('registra_jornada', true)
            ->with(['sede', 'asignacionesCentro.centro', 'interrupcionesPlantilla'])
            ->get();
        $pendientes = SolicitudVacaciones::query()
            ->with(['autor.sede', 'autor.asignacionesCentro.centro', 'autor.interrupcionesPlantilla'])
            ->where('estado', SolicitudVacaciones::PENDIENTE)
            ->get();
        $idsVisibles = $visibles->pluck('id')->all();
        $relevantes = $pendientes->filter(function (SolicitudVacaciones $solicitud) use ($hoy, $fin, $idsVisibles): bool {
            if (in_array($solicitud->id, $idsVisibles, true)) {
                return true;
            }

            return $solicitud->ends_on !== null
                && $solicitud->starts_on->lte($fin)
                && $solicitud->ends_on->gte($hoy);
        });
        $diasPorSolicitud = [];
        $hastaAusencias = $fin->copy();
        foreach ($relevantes as $solicitud) {
            $autor = $solicitud->autor;
            $diasPorSolicitud[$solicitud->id] = $autor
                ? $this->cupo->queCuentan($autor, $solicitud->starts_on->copy()->startOfDay(), $solicitud->ends_on->copy()->startOfDay())
                : collect();
            if ($solicitud->ends_on->gt($hastaAusencias)) {
                $hastaAusencias = $solicitud->ends_on->copy()->startOfDay();
            }
        }
        $aprobadas = Ausencia::query()
            ->with('user:id,name')
            ->where('tipo', 'vacaciones')
            ->whereDate('absence_date', '>=', $hoy->toDateString())
            ->whereDate('absence_date', '<=', $hastaAusencias->toDateString())
            ->get()
            ->groupBy(fn (Ausencia $ausencia): string => $ausencia->absence_date->toDateString());

        $pendientesEn = [];
        foreach ($relevantes as $solicitud) {
            $autor = $solicitud->autor;
            if (! $autor) {
                continue;
            }
            foreach ($diasPorSolicitud[$solicitud->id] as $fecha) {
                $sede = $autor->sedeEn(Carbon::parse($fecha));
                $ocupacion = $sede ? $this->de($personas, $fecha, $sede->id) : null;
                if (! $sede || ! $ocupacion || ! in_array($autor->id, $ocupacion['ids'], true)) {
                    continue;
                }
                $pendientesEn[$sede->id][$fecha][] = ['id' => $solicitud->id, 'nombre' => $autor->name];
            }
        }

        $festivos = $this->festivosDe($centros, $hoy, $fin);
        $usadas = [];
        $sedes = [];
        foreach ($centros as $centro) {
            $semanas = $this->semanas($centro, $hoy, $fin, $festivos[$centro->id] ?? collect(), $personas, $aprobadas, $pendientesEn[$centro->id] ?? []);
            $tieneGente = collect($semanas)->contains(fn (array $semana): bool => collect($semana['dias'])->contains(fn (array $dia): bool => $dia['plantilla'] > 0 || $dia['pendientes'] !== []));
            $laPideAlguien = isset($pendientesEn[$centro->id]);
            if (! $tieneGente && ! $laPideAlguien) {
                continue;
            }
            $usadas[$centro->id] = true;
            $sedes[] = [
                'id' => $centro->id,
                'nombre' => $centro->name,
                'minimo' => (int) $centro->minimo_presencia,
                'semanas' => $semanas,
            ];
        }

        $solicitudes = [];
        foreach ($visibles as $solicitud) {
            $completa = $pendientes->firstWhere('id', $solicitud->id);
            if (! $completa?->autor) {
                continue;
            }
            $solicitudes[$solicitud->id] = $this->resumen(
                $completa,
                $diasPorSolicitud[$solicitud->id] ?? collect(),
                $personas,
                $aprobadas,
                $pendientesEn,
                $centros,
            );
            if ($solicitudes[$solicitud->id]['sedeId'] && ! isset($usadas[$solicitudes[$solicitud->id]['sedeId']])) {
                $centro = $centros->firstWhere('id', $solicitudes[$solicitud->id]['sedeId']);
                if ($centro) {
                    $usadas[$centro->id] = true;
                    $sedes[] = [
                        'id' => $centro->id,
                        'nombre' => $centro->name,
                        'minimo' => (int) $centro->minimo_presencia,
                        'semanas' => $this->semanas($centro, $hoy, $fin, $festivos[$centro->id] ?? collect(), $personas, $aprobadas, $pendientesEn[$centro->id] ?? []),
                    ];
                }
            }
        }

        $preferida = $quienMira?->centro_id;
        $sedeInicial = collect($sedes)->contains(fn (array $sede): bool => $sede['id'] === $preferida)
            ? $preferida
            : ($sedes[0]['id'] ?? 0);

        return [
            'sedeInicial' => (int) $sedeInicial,
            'sedes' => $sedes,
            'solicitudes' => $solicitudes,
        ];
    }

    /**
     * @param  Collection<int, User>  $personas
     * @param  Collection<string, Collection<int, Ausencia>>  $aprobadas
     * @param  array<int, array<string, list<array{id: int, nombre: string}>>>  $pendientesEn
     * @param  Collection<int, Centro>  $centros
     * @param  Collection<int, string>  $dias
     * @return array{nivel: string, sedeId: ?int, resumen: string, dias: list<array{nivel: string, texto: string}>}
     */
    private function resumen(SolicitudVacaciones $solicitud, Collection $dias, Collection $personas, Collection $aprobadas, array $pendientesEn, Collection $centros): array
    {
        $autor = $solicitud->autor;
        $filas = [];
        foreach ($dias as $fecha) {
            $dia = Carbon::parse($fecha)->startOfDay();
            $sede = $autor->sedeEn($dia);
            if (! $sede) {
                continue;
            }
            $ocupacion = $this->de($personas, $fecha, $sede->id);
            $enPlantilla = in_array($autor->id, $ocupacion['ids'], true);
            $ausentes = $this->ausentes($aprobadas->get($fecha, collect()), $ocupacion['ids']);
            $resta = $enPlantilla && ! in_array($autor->id, $ausentes['ids'], true) ? 1 : 0;
            $plantilla = count($ocupacion['ids']);
            $quedan = $plantilla - count($ausentes['ids']) - $resta;
            $minimo = (int) $sede->minimo_presencia;
            $otros = collect($pendientesEn[$sede->id][$fecha] ?? [])
                ->reject(fn (array $persona): bool => $persona['id'] === $solicitud->id)
                ->pluck('nombre')
                ->all();
            $filas[] = [
                'fecha' => $dia,
                'sedeId' => $sede->id,
                'sede' => $sede->name,
                'nivel' => $enPlantilla ? $this->nivel($quedan, $minimo, $plantilla) : 'ajena',
                'texto' => $this->linea($dia, $sede->name, $quedan, $plantilla, $ausentes['nombres'], $otros, $enPlantilla, count($dias) > 0),
                'quedan' => $quedan,
                'plantilla' => $plantilla,
                'minimo' => $minimo,
            ];
        }

        if ($filas === []) {
            return [
                'nivel' => 'vacia',
                'sedeId' => $autor->centro_id,
                'resumen' => 'Esos días no cuentan: son fin de semana o festivo.',
                'dias' => [],
            ];
        }

        $variasSedes = collect($filas)->pluck('sedeId')->unique()->count() > 1;
        $filas = array_map(function (array $fila) use ($variasSedes): array {
            if ($variasSedes && $fila['nivel'] !== 'ajena') {
                $fila['texto'] = $fila['sede'].' · '.$fila['texto'];
            }

            return $fila;
        }, $filas);

        $cuentan = array_values(array_filter($filas, fn (array $fila): bool => $fila['nivel'] !== 'ajena'));
        if ($cuentan === []) {
            return [
                'nivel' => 'vacia',
                'sedeId' => $filas[0]['sedeId'],
                'resumen' => 'Esos días no cambian la plantilla de la sede.',
                'dias' => array_map(fn (array $fila): array => ['nivel' => 'vacia', 'texto' => $fila['texto']], $filas),
            ];
        }

        $peor = $cuentan[0];
        foreach ($cuentan as $fila) {
            if ($this->peso($fila['nivel']) < $this->peso($peor['nivel']) || ($fila['nivel'] === $peor['nivel'] && $fila['quedan'] < $peor['quedan'])) {
                $peor = $fila;
            }
        }

        return [
            'nivel' => $peor['nivel'],
            'sedeId' => $peor['sedeId'],
            'resumen' => $this->frase($peor, $centros->count() > 1 || $variasSedes),
            'dias' => array_map(fn (array $fila): array => ['nivel' => $fila['nivel'] === 'ajena' ? 'vacia' : $fila['nivel'], 'texto' => $fila['texto']], $filas),
        ];
    }

    /**
     * @param  Collection<int, User>  $personas
     * @param  Collection<string, true>  $festivos
     * @param  Collection<string, Collection<int, Ausencia>>  $aprobadas
     * @param  array<string, list<array{id: int, nombre: string}>>  $pendientes
     * @return list<array{mes: string, dias: list<array<string, mixed>>}>
     */
    private function semanas(Centro $centro, Carbon $hoy, Carbon $fin, Collection $festivos, Collection $personas, Collection $aprobadas, array $pendientes): array
    {
        $semanas = [];
        $cursor = $hoy->copy();
        while ($cursor->lte($fin)) {
            $fecha = $cursor->toDateString();
            if (! $cursor->isWeekend() && ! $festivos->has($fecha)) {
                $clave = $cursor->isoWeekYear().'-'.$cursor->isoWeek();
                $ocupacion = $this->de($personas, $fecha, $centro->id);
                $ausentes = $this->ausentes($aprobadas->get($fecha, collect()), $ocupacion['ids']);
                $plantilla = count($ocupacion['ids']);
                $quedan = $plantilla - count($ausentes['ids']);
                $nivel = $this->nivel($quedan, (int) $centro->minimo_presencia, $plantilla);
                $letras = ['L', 'M', 'X', 'J', 'V', 'S', 'D'];
                if (! isset($semanas[$clave])) {
                    $semanas[$clave] = [
                        'mes' => ucfirst($cursor->locale('es')->isoFormat('MMMM')),
                        'dias' => [],
                    ];
                }
                $semanas[$clave]['dias'][] = [
                    'fecha' => $fecha,
                    'letra' => $letras[$cursor->dayOfWeekIso - 1],
                    'numero' => $cursor->format('j'),
                    'corta' => $cursor->format('d/m'),
                    'nivel' => $nivel,
                    'plantilla' => $plantilla,
                    'fuera' => count($ausentes['ids']),
                    'pendientes' => array_column($pendientes[$fecha] ?? [], 'id'),
                    'titulo' => $cursor->format('d/m').' · '.$this->quedanTexto($quedan, $plantilla),
                ];
            }
            $cursor->addDay();
        }

        $mesAnterior = null;
        foreach ($semanas as &$semana) {
            $semana['muestraMes'] = $semana['mes'] !== $mesAnterior;
            $mesAnterior = $semana['mes'];
        }

        return array_values($semanas);
    }

    /**
     * @param  Collection<int, Centro>  $centros
     * @return array<int, Collection<string, true>>
     */
    private function festivosDe(Collection $centros, Carbon $hoy, Carbon $fin): array
    {
        $festivos = [];
        foreach ($centros as $centro) {
            $claves = collect();
            for ($anio = $hoy->year; $anio <= $fin->year; $anio++) {
                foreach ($this->festivos->delAnio($centro, $anio) as $dia) {
                    $claves->put($dia->holiday_date->toDateString(), true);
                }
            }
            $festivos[$centro->id] = $claves;
        }

        return $festivos;
    }

    /**
     * @param  Collection<int, User>  $personas
     * @return array{ids: list<int>, nombres: list<string>}
     */
    private function de(Collection $personas, string $fecha, int $centroId): array
    {
        if (! isset($this->ocupacion[$fecha])) {
            $dia = Carbon::parse($fecha)->startOfDay();
            $porCentro = [];
            foreach ($personas as $persona) {
                if (! $persona->estaEnPlantillaEl($dia)) {
                    continue;
                }
                $sede = $persona->sedeEn($dia);
                if (! $sede) {
                    continue;
                }
                $porCentro[$sede->id]['ids'][] = $persona->id;
                $porCentro[$sede->id]['nombres'][] = $persona->name;
            }
            $this->ocupacion[$fecha] = $porCentro;
        }

        return $this->ocupacion[$fecha][$centroId] ?? ['ids' => [], 'nombres' => []];
    }

    /**
     * @param  Collection<int, Ausencia>  $ausencias
     * @param  list<int>  $plantilla
     * @return array{ids: list<int>, nombres: list<string>}
     */
    private function ausentes(Collection $ausencias, array $plantilla): array
    {
        $ids = [];
        $nombres = [];
        foreach ($ausencias as $ausencia) {
            if (! in_array($ausencia->user_id, $plantilla, true) || in_array($ausencia->user_id, $ids, true)) {
                continue;
            }
            $ids[] = $ausencia->user_id;
            if ($ausencia->user) {
                $nombres[] = $ausencia->user->name;
            }
        }

        return ['ids' => $ids, 'nombres' => $nombres];
    }

    private function nivel(int $quedan, int $minimo, int $plantilla): string
    {
        if ($plantilla === 0) {
            return 'vacia';
        }
        if ($quedan < $minimo) {
            return 'corto';
        }
        if ($quedan === $minimo) {
            return 'justo';
        }

        return 'ok';
    }

    private function peso(string $nivel): int
    {
        return match ($nivel) {
            'corto' => 0,
            'justo' => 1,
            'vacia' => 2,
            default => 3,
        };
    }

    private function quedanTexto(int $quedan, int $plantilla): string
    {
        if ($plantilla === 0) {
            return 'sin plantilla';
        }

        return ($quedan === 1 ? 'queda ' : 'quedan ').$quedan.' de '.$plantilla;
    }

    /** @param  array{fecha: Carbon, sede: string, quedan: int, plantilla: int, minimo: int, nivel: string}  $peor */
    private function frase(array $peor, bool $conSede): string
    {
        $cuando = $peor['fecha']->format('d/m');
        $donde = $conSede ? ' en '.$peor['sede'] : '';
        if ($peor['nivel'] === 'ok') {
            return 'Cabe en la sede.';
        }
        if ($peor['nivel'] === 'vacia') {
            return 'No hay plantilla'.$donde.' el '.$cuando.'.';
        }
        $cifra = $this->quedanTexto($peor['quedan'], $peor['plantilla']);
        if ($peor['nivel'] === 'justo') {
            return 'Queda justo el '.$cuando.$donde.': '.$cifra.'. El mínimo es '.$peor['minimo'].'.';
        }

        return ucfirst($cifra).' el '.$cuando.$donde.'. El mínimo es '.$peor['minimo'].'.';
    }

    /**
     * @param  list<string>  $fuera
     * @param  list<string>  $otros
     */
    private function linea(Carbon $dia, string $sede, int $quedan, int $plantilla, array $fuera, array $otros, bool $enPlantilla, bool $hayDias): string
    {
        $nombres = ['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'];
        $etiqueta = ucfirst($nombres[$dia->dayOfWeekIso - 1]).' '.$dia->format('d/m');
        if (! $enPlantilla) {
            return $etiqueta.' · no está en la plantilla de '.$sede;
        }
        $texto = $etiqueta.' · '.$this->quedanTexto($quedan, $plantilla);
        if ($fuera !== []) {
            $texto .= ' · fuera: '.$this->lista($fuera);
        }
        if ($otros !== []) {
            $texto .= ' · también pendiente: '.$this->lista($otros);
        }

        return $hayDias ? $texto : $texto;
    }

    /** @param  list<string>  $nombres */
    private function lista(array $nombres): string
    {
        if (count($nombres) <= 3) {
            return implode(', ', $nombres);
        }

        return $nombres[0].', '.$nombres[1].' y '.(count($nombres) - 2).' más';
    }
}
