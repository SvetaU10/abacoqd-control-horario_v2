<?php

namespace App\Services;

use App\Exceptions\ReglaJornada;
use App\Models\Ausencia;
use App\Models\CargaAdministrativaDia;
use App\Models\CargaAdministrativaEvento;
use App\Models\CargaAdministrativaLote;
use App\Models\Jornada;
use App\Models\SolicitudVacaciones;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Cargas declaradas y previsiones separadas de los fichajes reales. */
class CargaAdministrativa
{
    public function __construct(private CalendarioMadridOficial $calendario, private NagerFestivos $festivosVigentes) {}

    /** @return array<string, mixed> */
    public function previsualizar(User $actor, array $ids, string $desde, string $hasta, array $horario): array
    {
        $actor = $this->autorizarResponsable($actor);
        [$ids, $inicio, $fin, $horas, $minutos] = $this->validarParametros($ids, $desde, $hasta, $horario);
        $personas = User::query()->whereIn('id', $ids)->orderBy('id')->get();
        if ($personas->count() !== count($ids) || $personas->contains(fn (User $p) => ! $p->active || ! $p->registraJornada())) {
            throw new ReglaJornada('Todas las personas deben tener una cuenta laboral activa.');
        }

        $festivos = [];
        $huellas = [];
        for ($anio = $inicio->year; $anio <= $fin->year; $anio++) {
            try {
                $snapshot = $this->calendario->snapshot($anio);
            } catch (\InvalidArgumentException|\RuntimeException) {
                throw new ReglaJornada('No hay una referencia oficial verificable de Madrid para ese año.');
            }
            $festivos[$anio] = array_fill_keys(array_column($snapshot['festivos'], 'date'), true);
            $huellas[$anio] = ['calendario' => $snapshot['hash'], 'snapshot' => $snapshot['fuentes'][0]['sha256_snapshot']];
        }
        $ausencias = Ausencia::query()->whereIn('user_id', $ids)->whereDate('absence_date', '>=', $desde)
            ->whereDate('absence_date', '<=', $hasta)->get()->mapWithKeys(fn (Ausencia $a) => [$a->user_id.':'.$a->absence_date->toDateString() => true]);
        $jornadas = Jornada::query()->whereIn('user_id', $ids)->whereDate('work_date', '>=', $desde)
            ->whereDate('work_date', '<=', $hasta)->get()->mapWithKeys(fn (Jornada $j) => [$j->user_id.':'.$j->work_date->toDateString() => true]);
        $activas = CargaAdministrativaDia::query()->whereIn('user_id', $ids)->where('estado', 'vigente')
            ->whereDate('fecha', '>=', $desde)->whereDate('fecha', '<=', $hasta)->pluck('clave_activa')->flip();
        $pendientes = SolicitudVacaciones::query()->whereIn('user_id', $ids)->where('estado', SolicitudVacaciones::PENDIENTE)
            ->whereDate('starts_on', '<=', $hasta)->whereDate('ends_on', '>=', $desde)->get()->groupBy('user_id');

        $corte = now()->toDateString();
        $dias = [];
        $exclusiones = ['fuera_periodo' => 0, 'descanso' => 0, 'festivo' => 0, 'ausencia' => 0, 'jornada_existente' => 0, 'carga_existente' => 0];
        $pendientesIncluidas = 0;
        foreach ($personas as $persona) {
            $adicionales = [];
            for ($anio = $inicio->year; $anio <= $fin->year; $anio++) {
                foreach ($this->festivosVigentes->dePersona($persona, $anio) as $festivo) {
                    $adicionales[$festivo->holiday_date->toDateString()] = true;
                }
            }
            for ($fecha = $inicio->copy(); $fecha->lte($fin); $fecha->addDay()) {
                $dia = $fecha->toDateString();
                $clave = $persona->id.':'.$dia;
                if (! $persona->estaEnPlantillaEl($fecha)) {
                    $exclusiones['fuera_periodo']++;
                } elseif ($fecha->isWeekend()) {
                    $exclusiones['descanso']++;
                } else {
                    $sede = $persona->sedeEn($fecha);
                    if (! $sede || $sede->municipio_ine !== '28079' || $sede->municipality !== 'Madrid' || $sede->comunidad_codigo !== 'ES-MD') {
                        throw new ReglaJornada('La carga requiere una sede histórica confirmada de Madrid capital.');
                    }
                    if (isset($festivos[$fecha->year][$dia]) || isset($adicionales[$dia])) {
                        $exclusiones['festivo']++;
                    } elseif ($ausencias->has($clave)) {
                        $exclusiones['ausencia']++;
                    } elseif ($jornadas->has($clave)) {
                        $exclusiones['jornada_existente']++;
                    } elseif ($activas->has($clave)) {
                        $exclusiones['carga_existente']++;
                    } else {
                        $tienePendiente = ($pendientes->get($persona->id) ?? collect())->contains(
                            fn (SolicitudVacaciones $s) => $s->starts_on->toDateString() <= $dia && $s->ends_on->toDateString() >= $dia,
                        );
                        $pendientesIncluidas += $tienePendiente ? 1 : 0;
                        $dias[] = ['user_id' => $persona->id, 'fecha' => $dia, 'clase' => $dia < $corte ? 'historica' : 'prevision',
                            'horario' => $horas, 'minutos' => $minutos, 'vacaciones_pendientes' => $tienePendiente];
                    }
                }
            }
        }
        $plan = ['version' => 1, 'autor_id' => $actor->id, 'ids' => $ids, 'desde' => $desde, 'hasta' => $hasta,
            'fecha_corte' => $corte, 'horario' => $horas, 'fuentes' => $huellas, 'dias' => $dias,
            'exclusiones' => $exclusiones, 'vacaciones_pendientes_incluidas' => $pendientesIncluidas];
        $plan['hash'] = $this->huella($plan);
        $plan['minutos'] = count($dias) * $minutos;

        return $plan;
    }

    public function aplicar(User $actor, array $ids, string $desde, string $hasta, array $horario, string $hash, string $motivo): CargaAdministrativaLote
    {
        $actor = $this->autorizarResponsable($actor);
        [$ids, , , $horas] = $this->validarParametros($ids, $desde, $hasta, $horario);
        $motivo = $this->motivo($motivo);
        if (! preg_match('/^[a-f0-9]{64}$/D', $hash)) {
            throw new ReglaJornada('La vista previa no es válida.');
        }

        return DB::transaction(function () use ($actor, $ids, $desde, $hasta, $horas, $hash, $motivo): CargaAdministrativaLote {
            $existente = CargaAdministrativaLote::query()->where('plan_hash', $hash)->lockForUpdate()->first();
            if ($existente) {
                if ($existente->autor_id !== $actor->id || $existente->desde->toDateString() !== $desde || $existente->hasta->toDateString() !== $hasta
                    || $existente->participantes !== $ids || $existente->horario !== $horas || $existente->motivo !== $motivo) {
                    throw new ReglaJornada('La carga previa pertenece a otros parámetros.');
                }

                return $existente;
            }
            User::query()->whereIn('id', $ids)->lockForUpdate()->get();
            $plan = $this->previsualizar($actor, $ids, $desde, $hasta, $horas);
            if (! hash_equals($plan['hash'], $hash)) {
                throw new ReglaJornada('Los datos han cambiado. Revisa de nuevo la vista previa.');
            }
            if ($plan['dias'] === []) {
                throw new ReglaJornada('No hay días nuevos que cargar.');
            }
            $lote = CargaAdministrativaLote::create(['autor_id' => $actor->id, 'desde' => $desde, 'hasta' => $hasta,
                'fecha_corte' => $plan['fecha_corte'], 'participantes' => $ids, 'horario' => $horas,
                'referencias' => $plan['fuentes'], 'exclusiones' => $plan['exclusiones'],
                'vacaciones_pendientes_incluidas' => $plan['vacaciones_pendientes_incluidas'], 'plan_hash' => $hash, 'motivo' => $motivo]);
            foreach ($plan['dias'] as $fila) {
                $dia = CargaAdministrativaDia::create(['lote_id' => $lote->id, 'user_id' => $fila['user_id'], 'fecha' => $fila['fecha'],
                    'clase' => $fila['clase'], 'estado' => 'vigente', 'clave_activa' => $fila['user_id'].':'.$fila['fecha'],
                    'minutos' => $fila['minutos'], ...$horas]);
                $this->evento($dia, $actor, 'creada', $motivo, ['vacaciones_pendientes' => $fila['vacaciones_pendientes']]);
            }

            return $lote;
        }, 3);
    }

    public function sustituirPorFichaje(User $actor, User $persona, string $fecha, Jornada $jornada): void
    {
        $this->autorizarCambio($actor, $persona, false);
        $this->fecha($fecha);
        if ($jornada->user_id !== $persona->id || $jornada->work_date->toDateString() !== $fecha
            || ! $jornada->tramos()->where('tipo', 'trabajo')->exists()) {
            throw new ReglaJornada('La sustitución requiere un fichaje real de esa persona y fecha.');
        }
        DB::transaction(function () use ($actor, $persona, $fecha, $jornada): void {
            $dia = $this->diaActivo($persona, $fecha);
            if (! $dia) {
                return;
            }
            $dia->update(['estado' => 'sustituida', 'clave_activa' => null, 'jornada_sustituta_id' => $jornada->id]);
            $this->evento($dia, $actor, 'sustituida', 'Sustituida por un fichaje real.', ['jornada_id' => $jornada->id]);
        }, 3);
    }

    public function cancelarPorAusencia(User $actor, User $persona, string $fecha, string $motivo): void
    {
        $this->autorizarCambio($actor, $persona, true);
        $this->fecha($fecha);
        $motivo = $this->motivo($motivo);
        DB::transaction(function () use ($actor, $persona, $fecha, $motivo): void {
            $ausencia = Ausencia::query()->where('user_id', $persona->id)->whereDate('absence_date', $fecha)->first();
            if (! $ausencia) {
                throw new ReglaJornada('Primero debe constar la ausencia aprobada.');
            }
            if ($this->hayFichajeReal($persona, $fecha)) {
                throw new ReglaJornada('Existe un fichaje real ese día. Revisa el conflicto sin borrarlo.');
            }
            $dia = $this->diaActivo($persona, $fecha);
            if (! $dia) {
                return;
            }
            $dia->update(['estado' => 'anulada_ausencia', 'clave_activa' => null]);
            $this->evento($dia, $actor, 'anulada_ausencia', $motivo, ['ausencia_id' => $ausencia->id]);
        }, 3);
    }

    public function restaurarTrasCorregirAusencia(User $actor, User $persona, string $fecha, string $motivo, ?int $diaId = null): void
    {
        $this->autorizarCambio($actor, $persona, true);
        $this->fecha($fecha);
        $motivo = $this->motivo($motivo);
        DB::transaction(function () use ($actor, $persona, $fecha, $motivo, $diaId): void {
            if (Ausencia::query()->where('user_id', $persona->id)->whereDate('absence_date', $fecha)->exists()
                || $this->hayFichajeReal($persona, $fecha) || $this->diaActivo($persona, $fecha)) {
                throw new ReglaJornada('La carga no puede restaurarse mientras exista ausencia, fichaje o carga vigente.');
            }
            $dia = CargaAdministrativaDia::query()->where('user_id', $persona->id)->whereDate('fecha', $fecha)
                ->where('estado', 'anulada_ausencia')->when($diaId !== null, fn ($q) => $q->whereKey($diaId))
                ->latest('id')->lockForUpdate()->first();
            if (! $dia) {
                throw new ReglaJornada('No hay carga anulada que restaurar.');
            }
            $horario = [];
            foreach (['morning_start', 'morning_end', 'afternoon_start', 'afternoon_end'] as $campo) {
                $horario[$campo] = substr($dia->{$campo}, 0, 5);
            }
            $plan = $this->previsualizar($actor, [$persona->id], $fecha, $fecha, $horario);
            if (count($plan['dias']) !== 1) {
                throw new ReglaJornada('La fecha ya no reúne las condiciones para restaurar la carga.');
            }
            $dia->update(['estado' => 'vigente', 'clave_activa' => $persona->id.':'.$fecha]);
            $this->evento($dia, $actor, 'restaurada', $motivo, []);
        }, 3);
    }

    /** @return array{minutos:int,minutos_historicos:int,minutos_prevision:int,dias:Collection} */
    public function resumen(User $persona, string $desde, string $hasta): array
    {
        $inicio = $this->fecha($desde);
        $fin = $this->fecha($hasta);
        if ($inicio->gt($fin)) {
            throw new ReglaJornada('El periodo no es válido.');
        }
        $dias = CargaAdministrativaDia::query()->where('user_id', $persona->id)->where('estado', 'vigente')
            ->whereDate('fecha', '>=', $desde)->whereDate('fecha', '<=', $hasta)->orderBy('fecha')->get();
        $jornadas = Jornada::query()->where('user_id', $persona->id)->whereDate('work_date', '>=', $desde)
            ->whereDate('work_date', '<=', $hasta)->get()->pluck('work_date')->map(fn (Carbon $d) => $d->toDateString())->flip();
        $ausencias = Ausencia::query()->where('user_id', $persona->id)->whereDate('absence_date', '>=', $desde)
            ->whereDate('absence_date', '<=', $hasta)->get()->pluck('absence_date')->map(fn (Carbon $d) => $d->toDateString())->flip();
        $dias = $dias->reject(fn (CargaAdministrativaDia $d) => ! $persona->estaEnPlantillaEl($d->fecha)
            || $jornadas->has($d->fecha->toDateString()) || $ausencias->has($d->fecha->toDateString()))->values();

        return ['minutos' => $dias->sum('minutos'),
            'minutos_historicos' => $dias->reject(fn (CargaAdministrativaDia $dia) => $this->esPrevision($dia))->sum('minutos'),
            'minutos_prevision' => $dias->filter(fn (CargaAdministrativaDia $dia) => $this->esPrevision($dia))->sum('minutos'), 'dias' => $dias];
    }

    /** La clase guardada refleja el momento de creación; la presentación depende de la fecha actual. */
    public function esPrevision(CargaAdministrativaDia $dia): bool
    {
        return $dia->fecha->toDateString() >= today()->toDateString();
    }

    private function autorizarResponsable(User $actor): User
    {
        $actual = User::query()->find($actor->id);
        if (! $actual || ! $actual->active || (! $actual->esJefa() && ! $actual->esTecnico())) {
            throw new ReglaJornada('La carga requiere un responsable o técnico activo.');
        }

        return $actual;
    }

    private function autorizarCambio(User $actor, User $persona, bool $soloJefe): void
    {
        $actual = User::query()->find($actor->id);
        if (! $actual || ! $actual->active || ($soloJefe ? ! $actual->esJefa() : ($actual->id !== $persona->id && ! $actual->esJefa()))) {
            throw new ReglaJornada('No tienes permiso para modificar esta carga.');
        }
    }

    private function validarParametros(array $ids, string $desde, string $hasta, array $horario): array
    {
        if ($ids === [] || count($ids) > 100 || collect($ids)->contains(fn ($id) => filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false)) {
            throw new ReglaJornada('Selecciona personas válidas.');
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        $inicio = $this->fecha($desde);
        $fin = $this->fecha($hasta);
        if ($inicio->gt($fin) || $inicio->diffInDays($fin) > 365) {
            throw new ReglaJornada('El periodo debe abarcar como máximo un año.');
        }
        $horas = [];
        foreach (['morning_start', 'morning_end', 'afternoon_start', 'afternoon_end'] as $clave) {
            $valor = $horario[$clave] ?? null;
            if (! is_string($valor) || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $valor)) {
                throw new ReglaJornada('El horario no es válido.');
            }
            $horas[$clave] = $valor;
        }
        if (! ($horas['morning_start'] < $horas['morning_end'] && $horas['morning_end'] <= $horas['afternoon_start']
            && ($horas['afternoon_start'] < $horas['afternoon_end'] || ($horas['afternoon_start'] === $horas['afternoon_end'] && $horas['morning_end'] === $horas['afternoon_start'])))) {
            throw new ReglaJornada('Revisa los tramos del horario.');
        }
        $min = fn (string $hora): int => (int) substr($hora, 0, 2) * 60 + (int) substr($hora, 3, 2);
        $minutos = $min($horas['morning_end']) - $min($horas['morning_start']) + $min($horas['afternoon_end']) - $min($horas['afternoon_start']);

        return [$ids, $inicio, $fin, $horas, $minutos];
    }

    private function fecha(string $valor): Carbon
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $valor)) {
            throw new ReglaJornada('La fecha no es válida.');
        }
        try {
            $fecha = Carbon::createFromFormat('!Y-m-d', $valor, config('app.timezone'));
        } catch (\Throwable) {
            throw new ReglaJornada('La fecha no es válida.');
        }
        if (! $fecha || $fecha->toDateString() !== $valor) {
            throw new ReglaJornada('La fecha no es válida.');
        }

        return $fecha->startOfDay();
    }

    private function motivo(string $valor): string
    {
        $valor = trim($valor);
        if ($valor === '' || mb_strlen($valor) > 500) {
            throw new ReglaJornada('Indica un motivo de hasta 500 caracteres.');
        }

        return $valor;
    }

    private function huella(array $plan): string
    {
        return hash('sha256', json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function diaActivo(User $persona, string $fecha): ?CargaAdministrativaDia
    {
        return CargaAdministrativaDia::query()->where('clave_activa', $persona->id.':'.$fecha)->lockForUpdate()->first();
    }

    private function hayFichajeReal(User $persona, string $fecha): bool
    {
        return Jornada::query()->where('user_id', $persona->id)->whereDate('work_date', $fecha)
            ->whereHas('tramos', fn ($query) => $query->where('tipo', 'trabajo'))->exists();
    }

    private function evento(CargaAdministrativaDia $dia, User $actor, string $tipo, string $motivo, array $detalles): void
    {
        CargaAdministrativaEvento::create(['dia_id' => $dia->id, 'actor_id' => $actor->id, 'tipo' => $tipo,
            'motivo' => $motivo, 'detalles' => $detalles, 'created_at' => now()]);
    }
}
