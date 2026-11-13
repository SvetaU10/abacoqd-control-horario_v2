<?php

namespace App\Services;

use App\Exceptions\ReglaJornada;
use App\Models\Aviso;
use App\Models\Correccion;
use App\Models\Explicacion;
use App\Models\FalloComun;
use App\Models\Horario;
use App\Models\Jornada;
use App\Models\Tramo;
use App\Models\User;
use App\Support\Tiempo;
use App\TipoJornada;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class JornadaService
{
    public const MINUTOS_HASTA_AVISO_DE_INICIO = 20;

    public const MINUTOS_ANTES_PARA_CONFIRMAR = 10;

    public const DIAS_HASTA_AVISAR_A_LA_JEFA = 7;

    public const DIAS_PARA_EXPLICAR_SIN_FICHAR = 7;

    public const CENTRO = 'Madrid';

    public function __construct(private NagerFestivos $festivos, private CargaAdministrativa $carga) {}

    public function pantalla(User $user, ?string $respuestaFestivo = null): array
    {
        $ahora = now();
        $hoy = $this->jornadaDe($user, $ahora);
        $pasada = $this->jornadaAbiertaAnterior($user, $ahora);
        $horario = $user->horarioEn($ahora);
        $expectativa = $this->expectativaDe($user, $ahora);
        $esperada = $expectativa['estado'] === 'laborable';
        $festivo = $this->festivoDe($user, $ahora);
        $abierto = $hoy?->tramoAbierto();
        $cierrePendiente = $this->cierrePendiente($hoy, $abierto, $horario, $esperada, $ahora);

        $estado = 'fuera';
        $estadoTexto = 'Fuera de jornada';
        $icono = 'fuera_de_jornada.svg';

        if ($pasada || $cierrePendiente) {
            $estado = 'pendiente';
            $estadoTexto = $pasada ? 'Tienes un día sin cerrar' : 'Cierre pendiente';
            $icono = 'pendiente.svg';
        } elseif ($abierto?->tipo === Tramo::TRABAJO) {
            $primero = $hoy->tramos->first(fn (Tramo $tramo) => $tramo->tipo === Tramo::TRABAJO);
            $estado = 'trabajando';
            $estadoTexto = 'Trabajando desde las '.Tiempo::hora($primero->started_at);
            $icono = 'trabajando.svg';
        } elseif ($abierto?->tipo === Tramo::PAUSA) {
            $estado = 'pausa';
            $estadoTexto = 'En pausa desde las '.Tiempo::hora($abierto->started_at);
            $icono = 'en_pausa.svg';
        } elseif ($hoy?->estaCerrada()) {
            $estado = 'cerrada';
            $estadoTexto = 'Jornada cerrada';
            $icono = 'fuera_de_jornada.svg';
        }

        $hasta = $hoy ? $this->hastaVisible($user, $hoy, $ahora) : $ahora;
        $minutosHoy = $hoy ? $this->minutosDeJornada($hoy, $hasta) : 0;
        $minutosCerrados = $hoy ? $this->minutosCerrados($hoy) : 0;
        $porEncima = $hoy ? $this->porEncima($user, $hoy, $hasta) : null;

        $banner = $this->banner($user, $ahora, $hoy, $pasada, $horario, $esperada, $abierto, $festivo, $respuestaFestivo, $cierrePendiente);

        if ($banner && $estado === 'fuera') {
            $icono = 'pendiente.svg';
        }

        $notas = [];
        if ($festivo && $respuestaFestivo === 'no') {
            $notas[] = 'Hoy es festivo en tu centro. No hay trabajo registrado.';
        }
        if ($festivo && $respuestaFestivo === 'si') {
            $notas[] = 'Has indicado que hoy hay trabajo real. El calendario sigue siendo el de tu centro.';
        }
        if ($this->ausenciaDe($user, $ahora)) {
            $notas[] = 'Hoy tienes una ausencia prevista. No hace falta fichar.';
        }

        $puedeAbrir = $user->estaEnPlantillaEl($ahora);
        $puedePorFestivo = ! $festivo || $respuestaFestivo === 'si';
        $primero = $hoy?->tramos->first(fn (Tramo $tramo) => $tramo->tipo === Tramo::TRABAJO);
        $tipo = $horario?->tipoJornada();
        if ($horario && $tipo === null) {
            $tipo = $horario->sinTarde() ? TipoJornada::Continua : TipoJornada::Partida;
        }
        $lunes = $ahora->copy()->locale('es')->startOfWeek(Carbon::MONDAY);
        $domingo = $ahora->copy()->locale('es')->endOfWeek(Carbon::SUNDAY);
        $minutosRealesSemana = $this->minutosSemana($user, $ahora);
        $cargaSemana = $this->carga->resumen($user, $lunes->toDateString(), $domingo->toDateString());
        $minutosSemana = $minutosRealesSemana + $cargaSemana['minutos'];
        $vivo = max(0, $minutosHoy - $minutosCerrados);

        return [
            'fecha' => Tiempo::fecha($ahora),
            'persona' => $user->name,
            'reloj' => $ahora->format('H:i:s'),
            'serverMs' => $ahora->getTimestamp() * 1000,
            'estado' => $estado,
            'estadoTexto' => $estadoTexto,
            'expectativa' => $expectativa,
            'icono' => $icono,
            'llevasTexto' => $hoy && ! $hoy->estaCerrada() ? 'Hoy llevas '.Tiempo::texto($minutosHoy).'.' : null,
            'minutosCerrados' => $minutosCerrados,
            'abiertoMs' => $abierto?->tipo === Tramo::TRABAJO && ! $cierrePendiente ? $abierto->started_at->getTimestamp() * 1000 : null,
            'salidaTexto' => $hoy?->estaCerrada() ? 'Salida a las '.$this->horaFin($hoy).'.' : null,
            'totalTexto' => $hoy?->estaCerrada() ? 'Hoy has trabajado '.Tiempo::texto($minutosHoy).'.' : null,
            'porEncimaTexto' => $porEncima ? 'Tiempo por encima del horario: '.Tiempo::texto($porEncima).'. No es una hora extra y no hace falta autorización.' : null,
            'horarioTexto' => $horario ? 'Tu horario es de '.$horario->texto().'.' : 'Todavía no tienes un horario asignado.',
            'comidaTexto' => $horario && ! $horario->sinTarde() ? 'Tu pausa de comida es de '.$horario->comidaTexto().'.' : null,
            'notaDia' => implode(' ', $notas),
            'banner' => $banner,
            'pendiente' => $banner !== null,
            'inicioTarde' => $this->inicioTarde($user, $ahora),
            'inicioPrevisto' => $horario ? $horario->corta($horario->morning_start) : null,
            'puedeEntrar' => $puedeAbrir && $puedePorFestivo && ! $pasada && (! $hoy || $this->soloFuera($hoy)) && ! $this->faltaExplicarAntesDeHoy($user, $ahora),
            'puedeSalir' => $abierto !== null && ! $pasada && ! $cierrePendiente,
            'puedePausar' => $abierto?->tipo === Tramo::TRABAJO && ! $cierrePendiente,
            'puedeVolver' => $abierto?->tipo === Tramo::PAUSA && ! $cierrePendiente,
            'puedeYaEstaba' => $puedeAbrir && $puedePorFestivo && ! $pasada && (! $hoy || $this->soloFuera($hoy)) && ! $this->faltaExplicarAntesDeHoy($user, $ahora),
            'hoyTexto' => Tiempo::corto($minutosHoy),
            'hoyLargo' => Tiempo::corto($minutosHoy),
            'semanaTexto' => Tiempo::corto($minutosSemana),
            'semanaCerrados' => max(0, $minutosSemana - $vivo),
            'semanaRealTexto' => Tiempo::corto($minutosRealesSemana),
            'semanaCargaTexto' => Tiempo::corto($cargaSemana['minutos_historicos']),
            'semanaPrevisionTexto' => Tiempo::corto($cargaSemana['minutos_prevision']),
            'semanaTieneCarga' => $cargaSemana['minutos'] > 0,
            'semanaRango' => $this->rangoSemana($lunes, $domingo),
            'saludo' => $ahora->hour < 14 ? 'Buenos días' : ($ahora->hour < 21 ? 'Buenas tardes' : 'Buenas noches'),
            'nombre' => explode(' ', trim($user->name))[0] ?: $user->name,
            'fechaLarga' => ucfirst($ahora->copy()->locale('es')->isoFormat('dddd, D [de] MMMM [de] YYYY')),
            'pastilla' => $this->pastilla($estado),
            'centroNombre' => $user->sedeEn($ahora)?->municipality ?? $user->municipality,
            'tipoLargo' => $tipo?->etiqueta(),
            'desdeTexto' => $primero ? 'Desde las '.Tiempo::hora($primero->started_at) : null,
            'previstoTexto' => $horario ? str_replace(' y ', ' · ', $horario->texto()) : null,
            'tramosHoy' => $hoy ? $this->tramosDeHoy($hoy) : [],
            'tramosAbiertos' => $hoy ? $hoy->tramos->whereNull('ended_at')->count() : 0,
            'barra' => $this->barra($horario, $hoy, $hasta),
            'esJefa' => $user->esJefa(),
            'horaInicioPrevista' => $horario ? $horario->corta($horario->morning_start) : null,
            'pideConfirmacionTemprana' => $this->horaInicioSiEsTemprano($user, $ahora) !== null,
            'tramos' => $hoy?->tramos ?? collect(),
            'horarioHoy' => $horario === null ? null : [
                'mananaInicio' => $horario->corta($horario->morning_start),
                'mananaFin' => $horario->corta($horario->morning_end),
                'tardeInicio' => $horario->corta($horario->afternoon_start),
                'tardeFin' => $horario->corta($horario->afternoon_end),
            ],
        ];
    }

    public function horaInicioSiEsTemprano(User $user, ?Carbon $ahora = null): ?string
    {
        $ahora ??= now();
        $horario = $user->horarioEn($ahora);
        if ($horario === null) {
            return null;
        }

        $prevista = $horario->corta($horario->morning_start);
        $inicio = $this->momento($ahora, $prevista);
        if ($ahora->copy()->addMinutes(self::MINUTOS_ANTES_PARA_CONFIRMAR)->lte($inicio)) {
            return $prevista;
        }

        return null;
    }

    public function entrar(User $user): string
    {
        $ahora = now();

        try {
            DB::transaction(function () use ($user, $ahora) {
                $this->bloquear($user);
                $this->asegurarPuedeAbrir($user, $ahora);
                $jornada = $this->jornadaParaAbrir($user, $ahora);
                $this->abrirTramo($jornada, Tramo::TRABAJO, $ahora, $ahora, false, 'ahora');
                $this->carga->sustituirPorFichaje($user, $user, $ahora->toDateString(), $jornada);
                $this->responder($user, 'inicio', $ahora);
            });
        } catch (UniqueConstraintViolationException) {
            $this->avisarJornadaYaEmpezada();
        }

        return 'Guardado. Entrada a las '.Tiempo::hora($ahora).'.';
    }

    public function entradaSegunMotivo(User $user, ?string $modo, ?string $hora = null): string
    {
        if ($this->inicioTarde($user, now()) && $modo !== null) {
            if ($modo === 'prevista') {
                return $this->reunion($user);
            }
            if ($modo === 'otra') {
                if (! $hora) {
                    throw new ReglaJornada('Elige la hora de entrada.');
                }

                return $this->yaEstaba($user, $hora);
            }
            if ($modo !== 'ahora') {
                throw new ReglaJornada('Elige cómo empezó tu jornada.');
            }
        } elseif ($modo === 'otra' && $hora) {
            return $this->yaEstaba($user, $hora);
        }

        return $this->entrar($user);
    }

    public function inicioTarde(User $user, ?Carbon $ahora = null): bool
    {
        $ahora ??= now();
        $horario = $user->horarioEn($ahora);

        return $horario !== null
            && $this->esDiaEsperado($user, $ahora)
            && $ahora->greaterThan($this->momento($ahora, $horario->corta($horario->morning_start)));
    }

    public function yaEstaba(User $user, string $hora): string
    {
        $ahora = now();
        $inicio = $this->momento($ahora, $hora);

        if ($inicio->greaterThan($ahora)) {
            throw new ReglaJornada('Esa hora todavía no ha llegado.');
        }

        try {
            DB::transaction(function () use ($user, $ahora, $inicio) {
                $this->bloquear($user);
                $pasada = $this->jornadaAbiertaAnterior($user, $ahora);
                if ($pasada) {
                    throw new ReglaJornada($this->textoOlvido($pasada));
                }

                $hoy = $this->jornadaDe($user, $ahora);
                if (! $hoy || $this->soloFuera($hoy)) {
                    $this->asegurarPuedeAbrir($user, $ahora);
                    $jornada = $hoy ?? Jornada::create([
                        'user_id' => $user->id,
                        'work_date' => $ahora->toDateString(),
                    ]);
                    if ($hoy && $this->solapa($hoy, $inicio, $ahora)) {
                        throw new ReglaJornada('Esa hora se cruza con un tramo que ya está guardado.');
                    }
                    $this->abrirTramo($jornada, Tramo::TRABAJO, $inicio, $ahora, false, 'hora_real');
                    $this->carga->sustituirPorFichaje($user, $user, $ahora->toDateString(), $jornada);
                    $this->responder($user, 'inicio', $ahora);

                    return;
                }

                if ($hoy->estaCerrada()) {
                    throw new ReglaJornada('Hoy ya has cerrado la jornada.');
                }

                $primero = $hoy->tramos()->where('tipo', Tramo::TRABAJO)->orderBy('started_at')->first();
                if (! $primero) {
                    throw new ReglaJornada('Hoy no hay un tramo de trabajo que mover.');
                }
                if ($inicio->greaterThanOrEqualTo($primero->started_at)) {
                    throw new ReglaJornada('Elige una hora anterior a las '.Tiempo::hora($primero->started_at).'.');
                }

                $this->anotar($user, $primero, 'started_at', $primero->started_at, $inicio, 'antes', null);
                $primero->update(['started_at' => $inicio]);
                $this->responder($user, 'inicio', $ahora);
            });
        } catch (UniqueConstraintViolationException) {
            $this->avisarJornadaYaEmpezada();
        }

        return 'Guardado. Trabajas desde las '.Tiempo::hora($inicio).'.';
    }

    public function pausar(User $user): string
    {
        $ahora = now();

        DB::transaction(function () use ($user, $ahora) {
            $this->bloquear($user);
            $abierto = $this->tramoAbiertoDeHoy($user, $ahora);
            if ($abierto?->tipo !== Tramo::TRABAJO) {
                throw new ReglaJornada('La pausa se marca cuando estás trabajando.');
            }
            $abierto->update(['ended_at' => $ahora]);
            $this->abrirTramo($abierto->jornada, Tramo::PAUSA, $ahora);
        });

        return 'Guardado. Pausa a las '.Tiempo::hora($ahora).'.';
    }

    public function volver(User $user): string
    {
        $ahora = now();

        DB::transaction(function () use ($user, $ahora) {
            $this->bloquear($user);
            $abierto = $this->tramoAbiertoDeHoy($user, $ahora);
            if ($abierto?->tipo !== Tramo::PAUSA) {
                throw new ReglaJornada('No hay una pausa abierta.');
            }
            $abierto->update(['ended_at' => $ahora]);
            $this->abrirTramo($abierto->jornada, Tramo::TRABAJO, $ahora);
        });

        return 'Guardado. Sigues desde las '.Tiempo::hora($ahora).'.';
    }

    public function salir(User $user): string
    {
        $ahora = now();

        DB::transaction(function () use ($user, $ahora) {
            $this->bloquear($user);
            $abierto = $this->tramoAbiertoDeHoy($user, $ahora);
            if (! $abierto) {
                throw new ReglaJornada('No hay una jornada abierta.');
            }
            $this->declararSalida($abierto, $user, $ahora, $ahora);
            $this->responder($user, 'cierre', $ahora);
        });

        return 'Guardado. Salida a las '.Tiempo::hora($ahora).'.';
    }

    public function reunion(User $user): string
    {
        $ahora = now();
        $horario = $user->horarioEn($ahora);
        if (! $horario) {
            throw new ReglaJornada('Todavía no tienes un horario asignado.');
        }
        $inicio = $this->momento($ahora, $horario->corta($horario->morning_start));
        if ($inicio->greaterThan($ahora)) {
            throw new ReglaJornada('Esa hora todavía no ha llegado.');
        }

        try {
            DB::transaction(function () use ($user, $ahora, $inicio) {
                $this->bloquear($user);
                $this->asegurarPuedeAbrir($user, $ahora);
                $jornada = $this->jornadaParaAbrir($user, $ahora);
                $jornada->load('tramos');
                if ($this->solapa($jornada, $inicio, $ahora)) {
                    throw new ReglaJornada('Esa hora se cruza con un tramo que ya está guardado.');
                }
                $this->abrirTramo($jornada, Tramo::TRABAJO, $inicio, $ahora, false, 'reunion');
                $this->carga->sustituirPorFichaje($user, $user, $ahora->toDateString(), $jornada);
                $this->responder($user, 'inicio', $ahora);
            });
        } catch (UniqueConstraintViolationException) {
            $this->avisarJornadaYaEmpezada();
        }

        return 'Guardado. Inicio a las '.Tiempo::hora($inicio).'. Lo anotaste a las '.Tiempo::hora($ahora).'.';
    }

    public function cerrarALas(User $user, string $hora): string
    {
        $ahora = now();
        $fin = $this->momento($ahora, $hora);

        DB::transaction(function () use ($user, $ahora, $fin) {
            $this->bloquear($user);
            $abierto = $this->tramoAbiertoDeHoy($user, $ahora);
            if (! $abierto) {
                throw new ReglaJornada('No hay una jornada abierta.');
            }
            if ($fin->greaterThan($ahora)) {
                throw new ReglaJornada('Esa hora todavía no ha llegado.');
            }
            if ($fin->lessThanOrEqualTo($abierto->started_at)) {
                throw new ReglaJornada('La salida tiene que ser después de las '.Tiempo::hora($abierto->started_at).'.');
            }
            $this->declararSalida($abierto, $user, $fin, $ahora);
            $this->responder($user, 'cierre', $ahora);
        });

        return 'Guardado. Salida a las '.Tiempo::hora($fin).'.';
    }

    public function seguir(User $user): string
    {
        $ahora = now();

        DB::transaction(function () use ($user, $ahora) {
            $this->bloquear($user);
            $hoy = $this->jornadaDe($user, $ahora);
            if (! $hoy?->tramoAbierto()) {
                throw new ReglaJornada('No hay una jornada abierta.');
            }
            $hoy->update(['sigue' => true]);
            $this->responder($user, 'cierre', $ahora);
        });

        return 'Guardado. Sigues. La salida será la hora a la que termines.';
    }

    public function registrarHoras(User $user, string $fecha, string $inicioHora, string $finHora): Jornada
    {
        $ahora = now();
        $dia = Carbon::parse($fecha, config('app.timezone'))->startOfDay();
        if ($dia->toDateString() > $ahora->toDateString()) {
            throw new ReglaJornada('Ese día todavía no ha llegado.');
        }
        if (! $user->esJefa() && $dia->lt($ahora->copy()->subDays(7)->startOfDay())) {
            throw new ReglaJornada('Solo puedes corregir un registro durante los primeros 7 días.');
        }

        $inicio = $this->momento($dia, $inicioHora);
        $fin = $this->momento($dia, $finHora);
        if ($fin->lessThanOrEqualTo($inicio)) {
            throw new ReglaJornada('La salida tiene que ser después de la entrada.');
        }
        if ($inicio->greaterThan($ahora) || $fin->greaterThan($ahora)) {
            throw new ReglaJornada('Esa hora todavía no ha llegado.');
        }

        return DB::transaction(function () use ($user, $ahora, $dia, $inicio, $fin) {
            $this->bloquear($user);
            $this->asegurarFechaDeAlta($user, $dia);
            $jornada = $this->jornadaDe($user, $dia);
            if ($jornada && $jornada->tramos->contains(fn (Tramo $tramo) => $tramo->tipo === Tramo::TRABAJO)) {
                throw new ReglaJornada('Ese día ya tiene horas registradas.');
            }
            if (! $jornada) {
                $jornada = Jornada::create([
                    'user_id' => $user->id,
                    'work_date' => $dia->toDateString(),
                ]);
            }
            Tramo::create([
                'jornada_id' => $jornada->id,
                'tipo' => Tramo::TRABAJO,
                'started_at' => $inicio,
                'ended_at' => $fin,
                'anotado_at' => $ahora,
                'fuera_del_equipo' => false,
                'cerrado_por' => $user->id,
                'cerrado_at' => $ahora,
            ]);
            $this->carga->sustituirPorFichaje($user, $user, $dia->toDateString(), $jornada);
            $this->marcarExplicados($user, $dia->toDateString());

            return $jornada;
        });
    }

    public function diasDeRetraso(Jornada $jornada): int
    {
        $jornada->loadMissing('tramos');
        $tramo = $jornada->tramos
            ->where('tipo', Tramo::TRABAJO)
            ->sortBy('anotado_at')
            ->first();

        if (! $tramo?->anotado_at) {
            return 0;
        }

        $diaTrabajo = Carbon::parse($jornada->work_date->toDateString(), config('app.timezone'))->startOfDay();
        $diaRegistro = $tramo->anotado_at->copy()->timezone(config('app.timezone'))->startOfDay();

        return max(0, $diaTrabajo->diffInDays($diaRegistro, false));
    }

    public function declararIncidencia(User $user, string $inicioHora, string $finHora, string $motivo): string
    {
        $ahora = now();
        $inicio = $this->momento($ahora, $inicioHora);
        $fin = $this->momento($ahora, $finHora);
        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new ReglaJornada('Escribe el motivo de la incidencia.');
        }
        if ($fin->lessThanOrEqualTo($inicio)) {
            throw new ReglaJornada('La salida tiene que ser después de la entrada.');
        }
        if ($fin->greaterThan($ahora) || $inicio->greaterThan($ahora)) {
            throw new ReglaJornada('Esa hora todavía no ha llegado.');
        }

        DB::transaction(function () use ($user, $ahora, $inicio, $fin, $motivo) {
            $this->bloquear($user);
            $this->asegurarFechaDeAlta($user, $ahora);
            $hoy = $this->jornadaDe($user, $ahora);
            if ($hoy && $this->solapa($hoy, $inicio, $fin)) {
                throw new ReglaJornada('Esa hora se cruza con un tramo que ya está guardado.');
            }
            if (! $hoy) {
                $hoy = Jornada::create([
                    'user_id' => $user->id,
                    'work_date' => $ahora->toDateString(),
                ]);
            }
            $this->abrirTramo($hoy, Tramo::TRABAJO, $inicio, $ahora, false, 'incidencia', $fin, $motivo);
            $this->carga->sustituirPorFichaje($user, $user, $ahora->toDateString(), $hoy);
        });

        return 'Guardado. Incidencia recibida a las '.Tiempo::hora($ahora).'. No es un fichaje en directo.';
    }

    public function excluirAvisosDeHorario(User $user, string $desde): void
    {
        Aviso::query()
            ->where('user_id', $user->id)
            ->where('cuenta', true)
            ->whereDate('work_date', '>=', $desde)
            ->update([
                'cuenta' => false,
                'exclusion' => 'horario',
            ]);
    }

    public function falloComun(string $fecha, string $nota): void
    {
        FalloComun::query()->updateOrCreate(
            ['falla_date' => $fecha],
            ['note' => $nota],
        );

        Aviso::query()
            ->whereDate('work_date', $fecha)
            ->update([
                'cuenta' => false,
                'exclusion' => 'comun',
            ]);
    }

    private function diaEstaCerrado(User $user, string $fecha): bool
    {
        $jornada = Jornada::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', $fecha)
            ->with('tramos')
            ->first();

        return $jornada?->estaCerrada() === true;
    }

    public function marcarExplicados(User $user, string $fecha): void
    {
        Aviso::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', $fecha)
            ->whereNull('responded_at')
            ->update(['responded_at' => now()]);
    }

    public function completarOlvido(User $user, string $hora): string
    {
        $ahora = now();
        $texto = '';

        DB::transaction(function () use ($user, $hora, $ahora, &$texto) {
            $this->bloquear($user);
            $pasada = $this->jornadaAbiertaAnterior($user, $ahora);
            if (! $pasada) {
                throw new ReglaJornada('No hay un día pendiente de cerrar.');
            }
            $abierto = $pasada->tramoAbierto();
            $fin = $this->momento($pasada->work_date, $hora);
            if ($fin->greaterThan($ahora)) {
                throw new ReglaJornada('Esa hora todavía no ha llegado.');
            }
            if ($fin->lessThanOrEqualTo($abierto->started_at)) {
                throw new ReglaJornada('La salida tiene que ser después de las '.Tiempo::hora($abierto->started_at).'.');
            }
            $this->declararSalida($abierto, $user, $fin, $ahora);
            $pasada->update(['closed_late' => true]);
            $this->responder($user, 'olvido', $pasada->work_date);
            $texto = 'Guardado. Salida el '.Tiempo::fecha($pasada->work_date).' a las '.Tiempo::hora($fin).'.';
        });

        return $texto;
    }

    public function corregir(User $actor, Tramo $tramo, string $campo, string $hora, string $motivo, ?string $nota): string
    {
        if (! array_key_exists($motivo, Correccion::MOTIVOS)) {
            throw new ReglaJornada('Elige un motivo de la lista.');
        }
        if (! in_array($campo, ['started_at', 'ended_at'], true)) {
            throw new ReglaJornada('Esa hora no se puede cambiar.');
        }

        $tramo->load('jornada.user');
        $jornada = $tramo->jornada;
        $duena = $jornada->user;

        if ($actor->id !== $duena->id && ! $actor->esJefa()) {
            throw new ReglaJornada('Solo puedes ver tu registro.');
        }
        if ($jornada->work_date->lt(now()->subDays(7)->startOfDay())) {
            throw new ReglaJornada('Solo puedes corregir un registro durante los primeros 7 días.');
        }

        $nota = $nota !== null ? trim($nota) : null;
        if ($nota === '') {
            $nota = null;
        }

        $guardada = null;

        DB::transaction(function () use ($actor, $tramo, $jornada, $duena, $campo, $hora, $motivo, $nota, &$guardada) {
            $this->bloquear($duena);
            $fresco = Tramo::query()->whereKey($tramo->id)->lockForUpdate()->first();
            if (! $fresco) {
                throw new ReglaJornada('Ese tramo ya no está.');
            }

            $anterior = $fresco->{$campo};
            if (! $anterior) {
                throw new ReglaJornada('Para cerrar la jornada usa Salida.');
            }

            $nueva = $this->momento($jornada->work_date, $hora);
            if ($nueva->greaterThan(now())) {
                throw new ReglaJornada('Esa hora todavía no ha llegado.');
            }
            if ($nueva->equalTo($anterior)) {
                throw new ReglaJornada('Esa hora ya es la que está guardada.');
            }

            $inicio = $campo === 'started_at' ? $nueva : $fresco->started_at;
            $fin = $campo === 'ended_at' ? $nueva : $fresco->ended_at;
            if (! $fin || $fin->lessThanOrEqualTo($inicio)) {
                throw new ReglaJornada('La salida tiene que ser después de la entrada.');
            }

            $this->anotar($actor, $fresco, $campo, $anterior, $nueva, $motivo, $nota);
            $fresco->update([$campo => $nueva]);
            $this->marcarExplicados($duena, $jornada->work_date->toDateString());
            $guardada = $nueva;
        });

        return 'Guardado. La hora ha quedado en las '.Tiempo::hora($guardada).'.';
    }

    public function ficharPausa(User $actor, Tramo $tramo, string $inicioHora, string $finHora, ?string $nota): string
    {
        $tramo->load('jornada.user');
        $jornada = $tramo->jornada;
        $duena = $jornada->user;

        if ($actor->id !== $duena->id && ! $actor->esJefa()) {
            throw new ReglaJornada('Solo puedes ver tu registro.');
        }
        if ($jornada->work_date->lt(now()->subDays(7)->startOfDay())) {
            throw new ReglaJornada('Solo puedes corregir un registro durante los primeros 7 días.');
        }

        $nota = $nota !== null ? trim($nota) : null;
        if ($nota === '') {
            $nota = null;
        }

        $inicio = $this->momento($jornada->work_date, $inicioHora);
        $fin = $this->momento($jornada->work_date, $finHora);
        $texto = '';
        DB::transaction(function () use ($actor, $tramo, $jornada, $duena, $inicioHora, $finHora, $inicio, $fin, $nota, &$texto) {
            $this->bloquear($duena);
            $fresco = Tramo::query()->whereKey($tramo->id)->lockForUpdate()->first();
            if (! $fresco) {
                throw new ReglaJornada('Ese tramo ya no está.');
            }

            $jornada->unsetRelation('tramos');
            $jornada->load('tramos');
            if ($jornada->tramos->contains('tipo', Tramo::PAUSA)) {
                if ($fresco->tipo !== Tramo::PAUSA) {
                    throw new ReglaJornada('Ese día ya tiene una pausa fichada.');
                }

                $inicioAnterior = $fresco->started_at;
                $finAnterior = $fresco->ended_at;
                if ($finAnterior && $inicioAnterior->equalTo($inicio) && $finAnterior->equalTo($fin)) {
                    $texto = 'La pausa ya está registrada de '.Tiempo::hora($inicio).' a '.Tiempo::hora($fin).'.';

                    return;
                }

                $trabajoInicio = $jornada->tramos
                    ->where('tipo', Tramo::TRABAJO)
                    ->min('started_at');
                $trabajoFin = $jornada->tramos
                    ->where('tipo', Tramo::TRABAJO)
                    ->filter(fn (Tramo $trabajo) => $trabajo->ended_at !== null)
                    ->max('ended_at');

                if ($fin->lessThanOrEqualTo($inicio)) {
                    throw new ReglaJornada('La hora de fin de la pausa tiene que ser posterior.');
                }
                if ($inicio->greaterThan(now()) || $fin->greaterThan(now())) {
                    throw new ReglaJornada('Esa hora todavía no ha llegado.');
                }
                if (! $trabajoInicio || ! $trabajoFin || $inicio->lt($trabajoInicio) || $fin->gt($trabajoFin)) {
                    throw new ReglaJornada('La pausa tiene que estar dentro del tramo de trabajo.');
                }

                $antes = $jornada->tramos->first(fn (Tramo $trabajo) => $trabajo->tipo === Tramo::TRABAJO
                    && $trabajo->ended_at?->equalTo($inicioAnterior));
                $despues = $finAnterior === null ? null : $jornada->tramos->first(fn (Tramo $trabajo) => $trabajo->tipo === Tramo::TRABAJO
                    && $trabajo->started_at->equalTo($finAnterior));

                if ($antes && $despues) {
                    if ($inicio->lt($antes->started_at) || ($despues->ended_at && $fin->gt($despues->ended_at))) {
                        throw new ReglaJornada('La pausa tiene que estar dentro de los tramos de trabajo que la rodean.');
                    }

                    if (! $antes->ended_at->equalTo($inicio)) {
                        $this->anotar($actor, $antes, 'ended_at', $antes->ended_at, $inicio, 'fichar_pausa', $nota);
                        $antes->update(['ended_at' => $inicio]);
                    }
                    if (! $despues->started_at->equalTo($fin)) {
                        $this->anotar($actor, $despues, 'started_at', $despues->started_at, $fin, 'fichar_pausa', $nota);
                        $despues->update(['started_at' => $fin]);
                    }
                }

                if ($inicioAnterior) {
                    $this->anotar($actor, $fresco, 'started_at', $inicioAnterior, $inicio, 'fichar_pausa', $nota);
                }
                if ($finAnterior) {
                    $this->anotar($actor, $fresco, 'ended_at', $finAnterior, $fin, 'fichar_pausa', $nota);
                }
                $fresco->update([
                    'started_at' => $inicio,
                    'ended_at' => $fin,
                    'anotado_at' => now(),
                ]);
                $this->marcarExplicados($duena, $jornada->work_date->toDateString());
                $texto = 'Guardado. Pausa de '.Tiempo::hora($inicio).' a '.Tiempo::hora($fin).'.';

                return;
            }
            if ($fresco->tipo !== Tramo::TRABAJO) {
                throw new ReglaJornada('Selecciona un tramo de trabajo.');
            }
            if (! $fresco->ended_at) {
                throw new ReglaJornada('La pausa se puede fichar cuando el tramo está cerrado.');
            }

            $inicio = $this->momento($jornada->work_date, $inicioHora);
            $fin = $this->momento($jornada->work_date, $finHora);
            if ($fin->lessThanOrEqualTo($inicio)) {
                throw new ReglaJornada('La hora de fin de la pausa tiene que ser posterior.');
            }
            if ($inicio->lessThan($fresco->started_at) || $fin->greaterThan($fresco->ended_at)) {
                throw new ReglaJornada('La pausa tiene que estar dentro del tramo de trabajo.');
            }

            $finAnterior = $fresco->ended_at;
            $fresco->update(['ended_at' => $inicio]);
            Tramo::create([
                'jornada_id' => $jornada->id,
                'tipo' => Tramo::PAUSA,
                'started_at' => $inicio,
                'ended_at' => $fin,
                'anotado_at' => now(),
            ]);
            Tramo::create([
                'jornada_id' => $jornada->id,
                'tipo' => Tramo::TRABAJO,
                'started_at' => $fin,
                'ended_at' => $finAnterior,
                'anotado_at' => now(),
                'cerrado_por' => $fresco->cerrado_por,
                'cerrado_at' => $fresco->cerrado_at,
            ]);
            $this->anotar($actor, $fresco, 'ended_at', $finAnterior, $inicio, 'fichar_pausa', $nota);
            $this->marcarExplicados($duena, $jornada->work_date->toDateString());
            $texto = 'Guardado. Pausa de '.Tiempo::hora($inicio).' a '.Tiempo::hora($fin).'.';
        });

        return $texto;
    }

    public function guardarHoras(User $actor, Tramo $tramo, ?string $inicioHora, ?string $finHora, string $motivo, ?string $nota): string
    {
        if (! array_key_exists($motivo, Correccion::MOTIVOS)) {
            throw new ReglaJornada('Elige un motivo de la lista.');
        }

        $inicioHora = is_string($inicioHora) && $inicioHora !== '' ? substr($inicioHora, 0, 5) : null;
        $finHora = is_string($finHora) && $finHora !== '' ? substr($finHora, 0, 5) : null;
        if ($inicioHora === null && $finHora === null) {
            throw new ReglaJornada('Elige la hora de inicio, la de fin, o las dos.');
        }

        $tramo->load('jornada.user');
        $jornada = $tramo->jornada;
        $duena = $jornada->user;

        if ($actor->id !== $duena->id && ! $actor->esJefa()) {
            throw new ReglaJornada('Solo puedes ver tu registro.');
        }
        if ($jornada->work_date->lt(now()->subDays(7)->startOfDay())) {
            throw new ReglaJornada('Para cambiar este día, habla con tu responsable.');
        }

        $nota = $nota !== null ? trim($nota) : null;
        if ($nota === '') {
            $nota = null;
        }

        $texto = '';

        DB::transaction(function () use ($actor, $tramo, $jornada, $duena, $inicioHora, $finHora, $motivo, $nota, &$texto) {
            $this->bloquear($duena);
            $fresco = Tramo::query()->whereKey($tramo->id)->lockForUpdate()->first();
            if (! $fresco) {
                throw new ReglaJornada('Ese tramo ya no está.');
            }

            $nuevoInicio = $inicioHora !== null ? $this->momento($jornada->work_date, $inicioHora) : $fresco->started_at;
            $nuevoFin = $finHora !== null ? $this->momento($jornada->work_date, $finHora) : $fresco->ended_at;
            $cambiaInicio = $inicioHora !== null && ! $nuevoInicio->equalTo($fresco->started_at);
            $cambiaFin = $finHora !== null && ($fresco->ended_at === null || ! $nuevoFin->equalTo($fresco->ended_at));

            if (! $cambiaInicio && ! $cambiaFin) {
                if ($nota === null) {
                    throw new ReglaJornada('Esas horas ya son las que están guardadas.');
                }
                Explicacion::create([
                    'user_id' => $duena->id,
                    'body' => $nota,
                    'work_date' => $jornada->work_date->toDateString(),
                ]);
                $this->marcarExplicados($duena, $jornada->work_date->toDateString());
                $texto = 'Guardado. La nota queda en el día.';

                return;
            }
            if ($nuevoInicio->greaterThan(now()) || ($nuevoFin && $nuevoFin->greaterThan(now()))) {
                throw new ReglaJornada('Esa hora todavía no ha llegado.');
            }
            if ($nuevoFin && $nuevoFin->lessThanOrEqualTo($nuevoInicio)) {
                throw new ReglaJornada('La salida tiene que ser después de la entrada.');
            }

            $teniaFin = $fresco->ended_at !== null;
            if ($cambiaInicio) {
                $this->anotar($actor, $fresco, 'started_at', $fresco->started_at, $nuevoInicio, $motivo, $nota);
            }
            if ($cambiaFin && $teniaFin) {
                $this->anotar($actor, $fresco, 'ended_at', $fresco->ended_at, $nuevoFin, $motivo, $nota);
            }

            $datos = [];
            if ($cambiaInicio) {
                $datos['started_at'] = $nuevoInicio;
            }
            if ($cambiaFin && $teniaFin) {
                $datos['ended_at'] = $nuevoFin;
            }
            if ($datos !== []) {
                $fresco->update($datos);
            }
            if ($cambiaFin && ! $teniaFin) {
                $this->declararSalida($fresco, $actor, $nuevoFin, now());
                $this->responder($duena, 'cierre', $jornada->work_date);
            }
            $this->marcarExplicados($duena, $jornada->work_date->toDateString());

            if ($cambiaInicio && $cambiaFin) {
                $texto = 'Guardado. El inicio ha quedado en las '.Tiempo::hora($nuevoInicio).' y el fin en las '.Tiempo::hora($nuevoFin).'.';
            } elseif ($cambiaInicio) {
                $texto = 'Guardado. La hora de inicio ha quedado en las '.Tiempo::hora($nuevoInicio).'.';
            } else {
                $texto = 'Guardado. La hora de fin ha quedado en las '.Tiempo::hora($nuevoFin).'.';
            }
        });

        return $texto;
    }

    public function minutosDeJornada(Jornada $jornada, ?Carbon $hasta = null): int
    {
        $hasta ??= now();

        return $this->minutosTrabajoSinPausas($jornada, $hasta);
    }

    public function porEncima(User $user, Jornada $jornada, ?Carbon $hasta = null): ?int
    {
        if (! $this->esDiaEsperado($user, $jornada->work_date)) {
            return null;
        }
        $horario = $user->horarioEn($jornada->work_date);
        if (! $horario) {
            return null;
        }
        $hecho = $this->minutosDeJornada($jornada, $hasta);
        $previsto = $this->minutosPrevistos($horario);
        $diferencia = $hecho - $previsto;

        return $diferencia > 0 ? $diferencia : null;
    }

    public function esDiaEsperado(User $user, Carbon $fecha): bool
    {
        return $this->expectativaDe($user, $fecha)['estado'] === 'laborable';
    }

    /**
     * @return array{estado: string, motivo: string, texto: string}
     */
    public function expectativaDe(User $user, Carbon $fecha): array
    {
        if (! $user->registraJornada()) {
            return ['estado' => 'exento', 'motivo' => 'cuenta_tecnica', 'texto' => 'Cuenta técnica sin registro laboral'];
        }

        if (! $user->estaEnPlantillaEl($fecha)) {
            return ['estado' => 'exento', 'motivo' => 'contrato', 'texto' => 'Fuera del periodo de contrato'];
        }
        if ($fecha->dayOfWeekIso >= 6) {
            return ['estado' => 'exento', 'motivo' => 'descanso', 'texto' => 'Día de descanso'];
        }
        if ($this->festivoDe($user, $fecha)) {
            return ['estado' => 'exento', 'motivo' => 'festivo', 'texto' => 'Festivo confirmado en su centro'];
        }
        if ($this->ausenciaDe($user, $fecha)) {
            return ['estado' => 'exento', 'motivo' => 'ausencia', 'texto' => 'Ausencia aprobada'];
        }
        if (! $user->horarioEn($fecha)) {
            return ['estado' => 'desconocido', 'motivo' => 'horario', 'texto' => 'Horario pendiente de asignar'];
        }
        if (! $user->sedeEn($fecha) || ! $this->festivos->coberturaConfirmada($user->sedeEn($fecha), $fecha->year, $fecha)) {
            return ['estado' => 'desconocido', 'motivo' => 'calendario', 'texto' => 'Calendario pendiente de comprobar'];
        }

        return ['estado' => 'laborable', 'motivo' => 'horario', 'texto' => 'Se espera trabajo según su horario'];
    }

    /**
     * Avisos que cuentan y siguen sin explicar. Solo los del año en curso,
     * el más antiguo primero. La entrada de hoy no entra: el día sigue
     * abierto y se puede fichar. Un día sin fichar deja de avisarse a los
     * siete días. Si el día ya está cerrado, el aviso de cierre o de salida
     * pendiente deja de contar. Un aviso de este año deja de contar también
     * cuando la persona lo explica. El histórico de otros años se conserva.
     */
    public function problemasSinExplicar(User $user, Carbon $ahora): int
    {
        return $this->avisosSinExplicar($user, $ahora)->count();
    }

    public function avisosSinExplicar(User $user, Carbon $ahora): Collection
    {
        $avisos = Aviso::query()
            ->where('user_id', $user->id)
            ->where('cuenta', true)
            ->whereNull('responded_at')
            ->whereDate('work_date', '>=', $ahora->copy()->startOfYear()->toDateString())
            ->whereDate('work_date', '<=', $ahora->toDateString())
            ->where(function ($consulta) use ($ahora) {
                $consulta->whereDate('work_date', '<', $ahora->toDateString())
                    ->orWhere('tipo', '!=', 'inicio');
            })
            ->where(function ($consulta) use ($ahora) {
                $consulta->where('tipo', '!=', 'inicio')
                    ->orWhereDate('work_date', '>=', $this->fechaLimiteAvisoSinFichar($ahora));
            })
            ->orderBy('work_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (Aviso $aviso) => $aviso->tipo !== 'inicio' || $this->esDiaEsperado($user, $aviso->work_date))
            ->values();

        $cubiertos = $avisos->filter(fn (Aviso $aviso) => in_array($aviso->tipo, ['cierre', 'olvido'], true)
            && $this->diaEstaCerrado($user, $aviso->work_date->toDateString()));

        if ($cubiertos->isNotEmpty()) {
            Aviso::query()
                ->whereIn('id', $cubiertos->modelKeys())
                ->whereNull('responded_at')
                ->update(['responded_at' => now()]);
            $avisos = $avisos->reject(fn (Aviso $aviso) => $cubiertos->contains('id', $aviso->id))->values();
        }

        return $avisos;
    }

    /**
     * @return list<array{fecha: string, dia: string, texto: string}>
     */
    public function pendientesDeExplicar(User $user, Carbon $ahora): array
    {
        return $this->avisosSinExplicar($user, $ahora)
            ->groupBy(fn (Aviso $aviso) => $aviso->work_date->toDateString())
            ->map(fn (Collection $delDia, string $fecha) => [
                'fecha' => $fecha,
                'dia' => Tiempo::fecha($delDia->first()->work_date),
                'texto' => $this->queFalta($delDia),
            ])
            ->values()
            ->all();
    }

    public function textoPendientes(Collection $avisos): string
    {
        $porDia = $avisos->groupBy(fn (Aviso $aviso) => $aviso->work_date->toDateString());
        $primero = $porDia->first();
        if ($porDia->count() === 1) {
            return 'El '.Tiempo::fecha($primero->first()->work_date).' '.$this->queFalta($primero).'.';
        }

        return 'Tienes '.$porDia->count().' días sin explicar. El más antiguo es el '.Tiempo::fecha($primero->first()->work_date).'.';
    }

    private function queFalta(Collection $delDia): string
    {
        $tipos = $delDia->pluck('tipo')->unique();
        $partes = [];
        if ($tipos->contains('olvido')) {
            $partes[] = 'falta la hora de salida';
        }
        if ($tipos->contains('inicio')) {
            $partes[] = 'no quedó registrada la entrada';
        }
        if ($tipos->contains('cierre')) {
            $partes[] = 'quedó el cierre sin completar';
        }

        return implode(' y ', $partes);
    }

    /**
     * Avisos sin explicar de este año y de hace más de siete días: lo que
     * el responsable debe mirar. Un día sin fichar no entra: a los siete días
     * deja de avisarse. Solo consulta. El más antiguo primero.
     */
    public function cierresSinResolverAntiguos(User $user, Carbon $ahora): int
    {
        $limite = $ahora->copy()->subDays(self::DIAS_HASTA_AVISAR_A_LA_JEFA)->toDateString();

        return Aviso::query()
            ->where('user_id', $user->id)
            ->where('cuenta', true)
            ->where('tipo', '!=', 'inicio')
            ->whereNull('responded_at')
            ->whereDate('work_date', '>=', $ahora->copy()->startOfYear()->toDateString())
            ->whereDate('work_date', '<', $limite)
            ->count();
    }

    public function fechaLimiteAvisoSinFichar(Carbon $ahora): string
    {
        return $ahora->copy()->subDays(self::DIAS_PARA_EXPLICAR_SIN_FICHAR)->toDateString();
    }

    public function diaSinCalendario(User $user, Carbon $ahora): bool
    {
        return ! $this->festivos->coberturaConfirmada($user->sedeEn($ahora), $ahora->year, $ahora);
    }

    /**
     * Cómo está hoy una persona, solo para consultar. No abre jornadas ni
     * genera avisos: mirar a alguien no puede cambiar nada de lo suyo.
     *
     * @return array{estado: string, texto: string, icono: string}
     */
    public function estadoDe(User $user, ?Carbon $ahora = null): array
    {
        $ahora ??= now();

        if (! $user->registraJornada()) {
            return $this->estadoDeConsulta('sin_registro', 'Cuenta técnica sin registro laboral', 'fuera_de_jornada.svg');
        }

        if (! $user->active) {
            return $this->estadoDeConsulta('cerrado', 'Acceso cerrado', 'fuera_de_jornada.svg');
        }

        if (! $user->starts_on || $ahora->toDateString() < $user->starts_on->toDateString()) {
            return $this->estadoDeConsulta('sin_empezar', 'Su registro todavía no ha empezado', 'fuera_de_jornada.svg');
        }

        if ($user->ends_on && $ahora->toDateString() > $user->ends_on->toDateString()) {
            return $this->estadoDeConsulta('cerrado', 'Su tiempo en la empresa ya ha terminado', 'fuera_de_jornada.svg');
        }

        $hoy = $this->jornadaDe($user, $ahora);
        $abierto = $hoy?->tramoAbierto();
        $horario = $user->horarioEn($ahora);
        $esperada = $this->esDiaEsperado($user, $ahora);

        if ($this->jornadaAbiertaAnterior($user, $ahora)) {
            return $this->estadoDeConsulta('pendiente', 'Tiene un día anterior sin cerrar', 'pendiente.svg');
        }
        if ($this->cierrePendiente($hoy, $abierto, $horario, $esperada, $ahora)) {
            return $this->estadoDeConsulta('pendiente', 'Cierre pendiente', 'pendiente.svg');
        }
        if ($abierto?->tipo === Tramo::TRABAJO) {
            return $this->estadoDeConsulta('trabajando', 'Trabajando', 'trabajando.svg');
        }
        if ($abierto?->tipo === Tramo::PAUSA) {
            return $this->estadoDeConsulta('pausa', 'En pausa', 'en_pausa.svg');
        }
        if ($hoy?->estaCerrada()) {
            return $this->estadoDeConsulta('cerrada', 'Jornada cerrada', 'fuera_de_jornada.svg');
        }
        if ($this->ausenciaDe($user, $ahora)) {
            return $this->estadoDeConsulta('ausencia', 'Ausencia prevista', 'fuera_de_jornada.svg');
        }
        if ($this->festivoDe($user, $ahora)) {
            return $this->estadoDeConsulta('festivo', 'Festivo en su centro', 'fuera_de_jornada.svg');
        }
        if ($esperada) {
            return $this->estadoDeConsulta('sin_empezar_hoy', 'Todavía no ha empezado', 'fuera_de_jornada.svg');
        }
        $expectativa = $this->expectativaDe($user, $ahora);
        if ($expectativa['estado'] === 'desconocido') {
            return $this->estadoDeConsulta('sin_planificacion', $expectativa['texto'], 'pendiente.svg');
        }

        return $this->estadoDeConsulta('fuera', 'Fuera de jornada', 'fuera_de_jornada.svg');
    }

    /**
     * Minutos de una jornada tal como están guardados. Un tramo que quedó
     * abierto en un día pasado no sigue sumando con el reloj: no se sabe a
     * qué hora terminó, así que no se inventa.
     */
    public function minutosRegistrados(User $user, Jornada $jornada, ?Carbon $ahora = null): int
    {
        return $this->minutosDeJornada($jornada, $this->hastaDeConsulta($user, $jornada, $ahora ?? now()));
    }

    /**
     * Tiempo por encima del horario de una jornada, con el mismo criterio que
     * {@see minutosRegistrados()}.
     */
    public function porEncimaRegistrado(User $user, Jornada $jornada, ?Carbon $ahora = null): ?int
    {
        return $this->porEncima($user, $jornada, $this->hastaDeConsulta($user, $jornada, $ahora ?? now()));
    }

    private function hastaDeConsulta(User $user, Jornada $jornada, Carbon $ahora): Carbon
    {
        $abierto = $jornada->tramoAbierto();

        if ($abierto && ! $jornada->work_date->isSameDay($ahora)) {
            return $abierto->started_at;
        }

        return $this->hastaVisible($user, $jornada, $ahora);
    }

    /**
     * Totales de un mes para consultar: lo registrado, lo previsto hasta hoy y
     * el tiempo por encima del horario. Ese tiempo no se clasifica como hora
     * extra: lo decide Ábaco.
     *
     * @param  Collection<int, Jornada>  $jornadas  Jornadas de ese mes, con sus tramos.
     * @return array{registrados: int, previstos: int, porEncima: int, dias: int}
     */
    public function resumenDelMes(User $user, Carbon $mes, Collection $jornadas, ?Carbon $ahora = null): array
    {
        $ahora ??= now();
        $hasta = $mes->copy()->endOfMonth()->min($ahora);
        $previstos = 0;

        for ($dia = $mes->copy()->startOfMonth(); $dia->lte($hasta); $dia->addDay()) {
            $horario = $user->horarioEn($dia);
            if ($horario && $this->esDiaEsperado($user, $dia)) {
                $previstos += $this->minutosPrevistos($horario);
            }
        }

        $registrados = $jornadas->sum(fn (Jornada $jornada) => $this->minutosRegistrados($user, $jornada, $ahora));
        $carga = $this->carga->resumen($user, $mes->copy()->startOfMonth()->toDateString(), $mes->copy()->endOfMonth()->toDateString());

        $resumen = [
            'registrados' => $registrados,
            'previstos' => $previstos,
            'porEncima' => $jornadas->sum(fn (Jornada $jornada) => $this->porEncimaRegistrado($user, $jornada, $ahora) ?? 0),
            'dias' => $jornadas->count(),
        ];

        if ($carga['minutos'] > 0) {
            $resumen['carga_historica'] = $carga['minutos_historicos'];
            $resumen['carga_prevision'] = $carga['minutos_prevision'];
            $resumen['total_combinado'] = $registrados + $carga['minutos'];
        }

        return $resumen;
    }

    /**
     * @return array{estado: string, texto: string, icono: string}
     */
    private function estadoDeConsulta(string $estado, string $texto, string $icono): array
    {
        return ['estado' => $estado, 'texto' => $texto, 'icono' => $icono];
    }

    private function banner(
        User $user,
        Carbon $ahora,
        ?Jornada $hoy,
        ?Jornada $pasada,
        ?Horario $horario,
        bool $esperada,
        ?Tramo $abierto,
        bool $festivo,
        ?string $respuestaFestivo,
        bool $cierrePendiente,
    ): ?array {
        $this->avisarHuecosSinEntrada($user, $ahora);

        if ($pasada) {
            $this->registrarAviso($user, 'olvido', $pasada->work_date);

            return [
                'texto' => $this->textoOlvido($pasada),
                'accion' => 'completar',
                'min' => Tiempo::hora($pasada->tramoAbierto()->started_at),
                'max' => '23:59',
            ];
        }

        if ($cierrePendiente && $horario) {
            $this->registrarAviso($user, 'cierre', $ahora);
            $fin = $horario->corta($horario->afternoon_end);

            return [
                'texto' => 'Son las '.$fin.'. ¿Terminas ya?',
                'accion' => 'cierre',
                'hora' => $fin,
            ];
        }

        if ($festivo && (! $hoy || $this->soloFuera($hoy)) && $respuestaFestivo === null) {
            return [
                'texto' => 'Hoy es festivo en tu centro. Encender el equipo no cuenta como trabajo. Seguimos el calendario del centro. ¿Hay trabajo real?',
                'accion' => 'festivo',
            ];
        }

        if ((! $hoy || $this->soloFuera($hoy)) && ($aviso = $this->faltaExplicarAntesDeHoy($user, $ahora))) {
            $fecha = $aviso->work_date->toDateString();

            return [
                'texto' => 'El '.Tiempo::fecha($aviso->work_date).' no quedó registrada la entrada.',
                'accion' => 'registro',
                'fecha' => $fecha,
                'jornada' => Jornada::query()
                    ->where('user_id', $user->id)
                    ->whereDate('work_date', $fecha)
                    ->value('id'),
            ];
        }

        $pendientes = $this->avisosSinExplicar($user, $ahora);
        if ($pendientes->isNotEmpty()) {
            $fecha = $pendientes->first()->work_date->toDateString();

            return [
                'texto' => $this->textoPendientes($pendientes),
                'accion' => 'registro',
                'fecha' => $fecha,
                'jornada' => Jornada::query()
                    ->where('user_id', $user->id)
                    ->whereDate('work_date', $fecha)
                    ->value('id'),
            ];
        }

        if ($user->esJefa() && $this->diaSinCalendario($user, $ahora)) {
            return [
                'texto' => 'El calendario de '.$ahora->year.' no está cargado. Solicita su carga a la persona responsable.',
                'accion' => 'calendario',
            ];
        }

        return null;
    }

    private function asegurarPuedeAbrir(User $user, Carbon $ahora): void
    {
        $this->avisarHuecosSinEntrada($user, $ahora);
        $this->asegurarFechaDeAlta($user, $ahora);
        $pasada = $this->jornadaAbiertaAnterior($user, $ahora);
        if ($pasada) {
            throw new ReglaJornada($this->textoOlvido($pasada));
        }
        if ($aviso = $this->faltaExplicarAntesDeHoy($user, $ahora)) {
            throw new ReglaJornada($this->textoBloqueoEntrada($aviso, $ahora).' Explícalo antes de empezar hoy.');
        }
        $hoy = $this->jornadaDe($user, $ahora);
        if ($hoy && ! $this->soloFuera($hoy)) {
            if ($hoy->estaCerrada()) {
                throw new ReglaJornada('Hoy ya has cerrado la jornada.');
            }
            throw new ReglaJornada('Hoy ya has empezado la jornada.');
        }
    }

    public function avisarSiFaltaEntrada(User $user, string $fecha): void
    {
        $dia = Carbon::parse($fecha, config('app.timezone'))->startOfDay();
        if ($dia->toDateString() >= now()->toDateString() || ! $this->esDiaEsperado($user, $dia)) {
            return;
        }

        $jornada = $this->jornadaDe($user, $dia);
        if ($jornada && $jornada->tramos->contains(fn (Tramo $tramo) => $tramo->tipo === Tramo::TRABAJO)) {
            return;
        }

        $this->registrarAviso($user, 'inicio', $dia);
    }

    /**
     * Días de trabajo entre el último fichaje y hoy, sin entrada.
     * Hay que explicarlos antes de empezar un día posterior.
     */
    private function avisarHuecosSinEntrada(User $user, Carbon $ahora): void
    {
        $ultimo = Jornada::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', '<', $ahora->toDateString())
            ->whereHas('tramos', fn ($consulta) => $consulta->where('tipo', Tramo::TRABAJO))
            ->max('work_date');

        if ($ultimo === null) {
            return;
        }

        $dia = Carbon::parse($ultimo, config('app.timezone'))->startOfDay()->addDay();
        $limite = Carbon::parse($this->fechaLimiteAvisoSinFichar($ahora), config('app.timezone'))->startOfDay();
        if ($dia->lt($limite)) {
            $dia = $limite;
        }
        $hoy = $ahora->copy()->startOfDay();

        while ($dia->lt($hoy)) {
            $this->avisarSiFaltaEntrada($user, $dia->toDateString());
            $dia->addDay();
        }
    }

    private function faltaExplicarAntesDeHoy(User $user, Carbon $ahora): ?Aviso
    {
        return Aviso::query()
            ->where('user_id', $user->id)
            ->where('tipo', 'inicio')
            ->where('cuenta', true)
            ->whereNull('responded_at')
            ->whereDate('work_date', '>=', $this->fechaLimiteAvisoSinFichar($ahora))
            ->whereDate('work_date', '<', $ahora->toDateString())
            ->orderBy('work_date')
            ->get()
            ->first(fn (Aviso $aviso) => $this->esDiaEsperado($user, $aviso->work_date));
    }

    private function textoBloqueoEntrada(Aviso $aviso, Carbon $ahora): string
    {
        if ($aviso->work_date->isSameDay($ahora->copy()->subDay())) {
            return 'Ayer no quedó registrada la entrada.';
        }

        return 'El '.Tiempo::fecha($aviso->work_date).' no quedó registrada la entrada.';
    }

    private function jornadaParaAbrir(User $user, Carbon $ahora): Jornada
    {
        return $this->jornadaDe($user, $ahora) ?? Jornada::create([
            'user_id' => $user->id,
            'work_date' => $ahora->toDateString(),
        ]);
    }

    private function soloFuera(Jornada $jornada): bool
    {
        return $jornada->estaCerrada()
            && $jornada->tramos->isNotEmpty()
            && $jornada->tramos->every(fn (Tramo $tramo) => $tramo->fuera_del_equipo || $tramo->situacion === 'incidencia');
    }

    private function cierrePendiente(?Jornada $hoy, ?Tramo $abierto, ?Horario $horario, bool $esperada, Carbon $ahora): bool
    {
        if ($hoy === null || $abierto === null || $horario === null || $hoy->sigue || ! $esperada) {
            return false;
        }

        $fin = $this->momento($hoy->work_date, $horario->corta($horario->afternoon_end));

        return $abierto->started_at->lessThan($fin)
            && $this->pasoLaHora($ahora, $horario->corta($horario->afternoon_end));
    }

    private function hastaVisible(User $user, Jornada $jornada, Carbon $ahora): Carbon
    {
        $abierto = $jornada->tramoAbierto();
        $horario = $user->horarioEn($jornada->work_date);
        if ($this->cierrePendiente($jornada, $abierto, $horario, $this->esDiaEsperado($user, $jornada->work_date), $ahora) && $horario) {
            return $this->momento($jornada->work_date, $horario->corta($horario->afternoon_end));
        }

        return $ahora;
    }

    private function solapa(Jornada $jornada, Carbon $inicio, Carbon $fin, ?int $excepto = null): bool
    {
        foreach ($jornada->tramos as $tramo) {
            if ($excepto !== null && $tramo->id === $excepto) {
                continue;
            }
            $tramoFin = $tramo->ended_at ?? now();
            if ($inicio->lt($tramoFin) && $fin->gt($tramo->started_at)) {
                return true;
            }
        }

        return false;
    }

    private function asegurarFechaDeAlta(User $user, Carbon $ahora): void
    {
        if (! $user->registraJornada()) {
            throw new ReglaJornada('Esta cuenta es exclusivamente técnica.');
        }

        if ($user->ends_on && $ahora->toDateString() > $user->ends_on->toDateString()) {
            throw new ReglaJornada('Tu tiempo en la empresa terminó el '.Tiempo::fecha($user->ends_on).'.');
        }

        if (! $user->starts_on || $ahora->toDateString() < $user->starts_on->toDateString()) {
            $fecha = $user->starts_on ? Tiempo::fecha($user->starts_on) : 'más adelante';
            throw new ReglaJornada('Tu registro empieza el '.$fecha.'.');
        }
    }

    private function jornadaDe(User $user, Carbon $fecha): ?Jornada
    {
        return Jornada::query()
            ->with('tramos')
            ->where('user_id', $user->id)
            ->whereDate('work_date', $fecha->toDateString())
            ->first();
    }

    private function jornadaAbiertaAnterior(User $user, Carbon $ahora): ?Jornada
    {
        return Jornada::query()
            ->with('tramos')
            ->where('user_id', $user->id)
            ->whereDate('work_date', '<', $ahora->toDateString())
            ->whereHas('tramos', fn ($q) => $q->whereNull('ended_at'))
            ->orderBy('work_date')
            ->first();
    }

    private function tramoAbiertoDeHoy(User $user, Carbon $ahora): ?Tramo
    {
        $hoy = $this->jornadaDe($user, $ahora);

        return $hoy?->tramoAbierto();
    }

    private function abrirTramo(
        Jornada $jornada,
        string $tipo,
        Carbon $inicio,
        ?Carbon $anotado = null,
        bool $fuera = false,
        ?string $situacion = null,
        ?Carbon $fin = null,
        ?string $nota = null,
    ): void {
        Tramo::create([
            'jornada_id' => $jornada->id,
            'tipo' => $tipo,
            'started_at' => $inicio,
            'ended_at' => $fin,
            'anotado_at' => $anotado ?? $inicio,
            'fuera_del_equipo' => $fuera,
            'situacion' => $situacion,
            'nota' => $nota,
        ]);
    }

    private function bloquear(User $user): void
    {
        User::query()->whereKey($user->id)->lockForUpdate()->first();
    }

    private function momento(Carbon $fecha, string $hora): Carbon
    {
        $hora = substr($hora, 0, 5);

        return Carbon::parse($fecha->toDateString().' '.$hora, config('app.timezone'));
    }

    private function pasoLaHora(Carbon $ahora, string $hora): bool
    {
        return $ahora->greaterThanOrEqualTo($this->momento($ahora, $hora));
    }

    private function minutosPrevistos(Horario $horario): int
    {
        return $this->duracion($horario->morning_start, $horario->morning_end)
            + $this->duracion($horario->afternoon_start, $horario->afternoon_end);
    }

    private function duracion(string $inicio, string $fin): int
    {
        $desde = Carbon::parse(substr($inicio, 0, 5));
        $hasta = Carbon::parse(substr($fin, 0, 5));

        return (int) round($desde->diffInMinutes($hasta, true));
    }

    private function minutosCerrados(Jornada $jornada): int
    {
        return $this->minutosTrabajoSinPausas($jornada, now(), true);
    }

    private function minutosTrabajoSinPausas(Jornada $jornada, Carbon $hasta, bool $soloCerrados = false): int
    {
        $pausas = $this->unirIntervalos($jornada->tramos
            ->where('tipo', Tramo::PAUSA)
            ->filter(fn (Tramo $pausa) => $pausa->ended_at !== null)
            ->map(fn (Tramo $pausa) => [$pausa->started_at->getTimestamp(), $pausa->ended_at->getTimestamp()])
            ->all());
        $trabajos = $this->unirIntervalos($jornada->tramos
            ->where('tipo', Tramo::TRABAJO)
            ->filter(fn (Tramo $trabajo) => ! $soloCerrados || $trabajo->ended_at !== null)
            ->map(fn (Tramo $trabajo) => [$trabajo->started_at->getTimestamp(), ($trabajo->ended_at ?? $hasta)->getTimestamp()])
            ->all());
        $segundos = 0;

        foreach ($trabajos as [$inicio, $fin]) {
            $cursor = $inicio;
            foreach ($pausas as [$pausaInicio, $pausaFin]) {
                if ($pausaFin <= $cursor || $pausaInicio >= $fin) {
                    continue;
                }

                $segundos += max(0, $pausaInicio - $cursor);
                $cursor = max($cursor, $pausaFin);
                if ($cursor >= $fin) {
                    break;
                }
            }

            $segundos += max(0, $fin - $cursor);
        }

        return (int) round($segundos / 60);
    }

    /**
     * @param  list<array{0: int, 1: int}>  $intervalos
     * @return list<array{0: int, 1: int}>
     */
    private function unirIntervalos(array $intervalos): array
    {
        usort($intervalos, fn (array $uno, array $otro) => $uno[0] <=> $otro[0]);
        $unidos = [];

        foreach ($intervalos as [$inicio, $fin]) {
            if ($fin <= $inicio) {
                continue;
            }

            $ultimo = count($unidos) - 1;
            if ($ultimo >= 0 && $inicio <= $unidos[$ultimo][1]) {
                $unidos[$ultimo][1] = max($unidos[$ultimo][1], $fin);
            } else {
                $unidos[] = [$inicio, $fin];
            }
        }

        return $unidos;
    }

    private function minutosSemana(User $user, Carbon $ahora): int
    {
        $lunes = $ahora->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
        $domingo = $ahora->copy()->endOfWeek(Carbon::SUNDAY)->toDateString();
        $jornadas = Jornada::query()
            ->with('tramos')
            ->where('user_id', $user->id)
            ->whereBetween('work_date', [$lunes, $domingo])
            ->get();

        return $jornadas->sum(fn (Jornada $jornada) => $this->minutosRegistrados($user, $jornada, $ahora));
    }

    private function horaFin(Jornada $jornada): string
    {
        $ultimo = $jornada->tramos->filter(fn (Tramo $tramo) => $tramo->ended_at !== null)->sortBy('ended_at')->last();

        return $ultimo ? Tiempo::hora($ultimo->ended_at) : '';
    }

    /**
     * @return array{texto: string, clase: string}
     */
    private function pastilla(string $estado): array
    {
        return match ($estado) {
            'trabajando' => ['texto' => 'En curso', 'clase' => 'en-curso'],
            'pausa' => ['texto' => 'En pausa', 'clase' => 'en-pausa'],
            'pendiente' => ['texto' => 'Pendiente', 'clase' => 'pendiente'],
            'cerrada' => ['texto' => 'Cerrada', 'clase' => 'cerrada'],
            default => ['texto' => 'Fuera', 'clase' => 'fuera'],
        };
    }

    private function rangoSemana(Carbon $lunes, Carbon $domingo): string
    {
        $mes = rtrim($domingo->isoFormat('MMM'), '.');
        if ($lunes->month === $domingo->month) {
            return $lunes->day.'-'.$domingo->day.' '.$mes;
        }

        return $lunes->day.' '.rtrim($lunes->isoFormat('MMM'), '.').'-'.$domingo->day.' '.$mes;
    }

    /**
     * @return list<array{tipo: string, titulo: string, detalle: string, horas: string, abierto: bool}>
     */
    private function tramosDeHoy(Jornada $jornada): array
    {
        return $jornada->tramos->map(function (Tramo $tramo) {
            $abierto = $tramo->ended_at === null;

            return [
                'tipo' => $tramo->tipo,
                'titulo' => $tramo->fuera_del_equipo ? 'Sin el portátil' : ($tramo->tipo === Tramo::PAUSA ? 'Pausa' : 'Trabajo'),
                'detalle' => $abierto ? 'Tramo en curso' : 'Cerrado',
                'horas' => Tiempo::hora($tramo->started_at).'–'.($abierto ? 'ahora' : Tiempo::hora($tramo->ended_at)),
                'abierto' => $abierto,
            ];
        })->all();
    }

    /**
     * @return array{previsto: list<array{left: float, width: float}>, hecho: list<array{left: float, width: float, abierto: bool}>}
     */
    private function barra(?Horario $horario, ?Jornada $hoy, Carbon $hasta): array
    {
        $previsto = [];
        if ($horario) {
            $manana = $this->tramoEje($this->minutosDeHora($horario->morning_start), $this->minutosDeHora($horario->morning_end));
            if ($manana) {
                $previsto[] = $manana;
            }
            if (! $horario->sinTarde()) {
                $tarde = $this->tramoEje($this->minutosDeHora($horario->afternoon_start), $this->minutosDeHora($horario->afternoon_end));
                if ($tarde) {
                    $previsto[] = $tarde;
                }
            }
        }

        $hecho = [];
        $pausas = ($hoy?->tramos ?? collect())
            ->where('tipo', Tramo::PAUSA)
            ->filter(fn (Tramo $pausa) => $pausa->ended_at !== null)
            ->sortBy('started_at');
        foreach ($hoy?->tramos ?? [] as $tramo) {
            if ($tramo->tipo !== Tramo::TRABAJO) {
                continue;
            }
            $fin = $tramo->ended_at ?? $hasta;
            foreach ($this->segmentosSinPausas($tramo->started_at, $fin, $pausas) as [$inicio, $segmentoFin]) {
                $trozo = $this->tramoEje($this->minutosDelDia($inicio), $this->minutosDelDia($segmentoFin));
                if ($trozo) {
                    $trozo['abierto'] = $tramo->ended_at === null;
                    $hecho[] = $trozo;
                }
            }
        }

        return ['previsto' => $previsto, 'hecho' => $hecho];
    }

    /**
     * @param  Collection<int, Tramo>  $pausas
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    private function segmentosSinPausas(Carbon $inicio, Carbon $fin, Collection $pausas): array
    {
        $segmentos = [];
        $cursor = $inicio;

        foreach ($pausas as $pausa) {
            if ($pausa->ended_at->lessThanOrEqualTo($cursor) || $pausa->started_at->greaterThanOrEqualTo($fin)) {
                continue;
            }

            $pausaInicio = $pausa->started_at->greaterThan($cursor) ? $pausa->started_at : $cursor;
            if ($pausaInicio->greaterThan($cursor)) {
                $segmentos[] = [$cursor, $pausaInicio];
            }
            $cursor = $pausa->ended_at->greaterThan($cursor) ? $pausa->ended_at : $cursor;
            if ($cursor->greaterThanOrEqualTo($fin)) {
                break;
            }
        }

        if ($cursor->lessThan($fin)) {
            $segmentos[] = [$cursor, $fin];
        }

        return $segmentos;
    }

    /**
     * @return array{left: float, width: float}|null
     */
    private function tramoEje(int $inicio, int $fin): ?array
    {
        $desde = 7 * 60;
        $hasta = 21 * 60;
        $a = max($desde, min($hasta, $inicio));
        $b = max($desde, min($hasta, $fin));
        if ($b <= $a) {
            return null;
        }

        return [
            'left' => round(($a - $desde) / ($hasta - $desde) * 100, 2),
            'width' => round(($b - $a) / ($hasta - $desde) * 100, 2),
        ];
    }

    private function minutosDeHora(string $hora): int
    {
        [$horas, $minutos] = array_pad(explode(':', substr($hora, 0, 5)), 2, '0');

        return ((int) $horas) * 60 + (int) $minutos;
    }

    private function minutosDelDia(Carbon $momento): int
    {
        $local = $momento->timezone(config('app.timezone'));

        return ((int) $local->format('H')) * 60 + (int) $local->format('i');
    }

    private function festivoDe(User $user, Carbon $fecha): bool
    {
        return $this->festivos->es($user->sedeEn($fecha), $fecha);
    }

    private function ausenciaDe(User $user, Carbon $fecha): bool
    {
        return $user->ausencias()->whereDate('absence_date', $fecha->toDateString())->exists();
    }

    private function textoOlvido(Jornada $jornada): string
    {
        $dia = $jornada->work_date->locale('es')->isoFormat('dddd');

        return 'No cerraste el '.$dia.'. ¿A qué hora terminaste?';
    }

    /**
     * Decide si hoy toca mandar por correo el aviso de que la jornada no ha
     * empezado. Pasa si es un día de trabajo, ya han pasado unos minutos desde
     * la hora de inicio, el horario no ha terminado y no hay nada anotado.
     * Deja el aviso guardado y reserva el envío: devuelve la hora prevista
     * solo la primera vez, para que un segundo intento no repita el correo.
     */
    public function avisarDeInicio(User $user, ?Carbon $ahora = null): ?string
    {
        $ahora ??= now();
        $horario = $user->horarioEn($ahora);

        if (! $user->active || ! $horario || ! $this->esDiaEsperado($user, $ahora)) {
            return null;
        }

        $hoy = $this->jornadaDe($user, $ahora);
        if ($hoy && $hoy->tramos->contains(fn (Tramo $tramo) => $tramo->situacion === 'incidencia')) {
            return null;
        }
        if ($hoy && ! $this->soloFuera($hoy)) {
            return null;
        }

        $prevista = $horario->corta($horario->morning_start);
        $aviso = $this->momento($ahora, $prevista)->addMinutes(self::MINUTOS_HASTA_AVISO_DE_INICIO);
        $cierre = $this->momento($ahora, $horario->corta($horario->afternoon_end));
        if ($ahora->lessThan($aviso) || $ahora->greaterThanOrEqualTo($cierre)) {
            return null;
        }

        $this->registrarAviso($user, 'inicio', $ahora);

        $reservado = Aviso::query()
            ->where('user_id', $user->id)
            ->where('tipo', 'inicio')
            ->whereDate('work_date', $ahora->toDateString())
            ->whereNull('correo_enviado_at')
            ->update(['correo_enviado_at' => $ahora]);

        return $reservado === 1 ? $prevista : null;
    }

    /**
     * Deshace la reserva si el correo no salió, para reintentarlo en la
     * siguiente pasada.
     */
    public function liberarAvisoDeInicio(User $user, Carbon $ahora): void
    {
        Aviso::query()
            ->where('user_id', $user->id)
            ->where('tipo', 'inicio')
            ->whereDate('work_date', $ahora->toDateString())
            ->update(['correo_enviado_at' => null]);
    }

    private function registrarAviso(User $user, string $tipo, Carbon $fecha): void
    {
        $yaExiste = Aviso::query()
            ->where('user_id', $user->id)
            ->where('tipo', $tipo)
            ->whereDate('work_date', $fecha->toDateString())
            ->exists();

        if ($yaExiste) {
            return;
        }

        $comun = FalloComun::query()->whereDate('falla_date', $fecha->toDateString())->exists();

        try {
            Aviso::query()->create([
                'user_id' => $user->id,
                'tipo' => $tipo,
                'work_date' => $fecha->toDateString(),
                'cuenta' => ! $comun,
                'exclusion' => $comun ? 'comun' : null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Otro proceso guardó el mismo aviso a la vez: no hay nada que hacer.
        }
    }

    private function responder(User $user, string $tipo, Carbon $fecha): void
    {
        Aviso::query()
            ->where('user_id', $user->id)
            ->where('tipo', $tipo)
            ->whereDate('work_date', $fecha->toDateString())
            ->whereNull('responded_at')
            ->update(['responded_at' => now()]);
    }

    private function declararSalida(Tramo $tramo, User $user, Carbon $fin, Carbon $declarada): void
    {
        $datos = ['ended_at' => $fin];

        if ($tramo->cerrado_at === null) {
            $datos['cerrado_por'] = $user->id;
            $datos['cerrado_at'] = $declarada;
        }

        $tramo->update($datos);
    }

    private function anotar(User $actor, Tramo $tramo, string $campo, Carbon $anterior, Carbon $nueva, string $motivo, ?string $nota): void
    {
        Correccion::create([
            'tramo_id' => $tramo->id,
            'user_id' => $actor->id,
            'field' => $campo,
            'previous_value' => $anterior,
            'new_value' => $nueva,
            'reason' => $motivo,
            'note' => $nota,
        ]);
    }

    private function avisarJornadaYaEmpezada(): never
    {
        throw new ReglaJornada('Hoy ya has empezado la jornada.');
    }
}
