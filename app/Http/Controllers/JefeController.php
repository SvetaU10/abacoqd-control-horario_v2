<?php

namespace App\Http\Controllers;

use App\Exceptions\ReglaJornada;
use App\Models\Ausencia;
use App\Models\Aviso;
use App\Models\Consulta;
use App\Models\Jornada;
use App\Models\SolicitudVacaciones;
use App\Models\User;
use App\Services\CargaAdministrativa;
use App\Services\CupoVacaciones;
use App\Services\JornadaService;
use App\Services\RegistroCsv;
use App\Support\Tiempo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Funciones de dirección de el responsable: estado del equipo, resumen del mes y
 * registro de cada persona. Solo consulta: ninguna acción de aquí cambia un
 * horario, una hora o un acceso. Lo único que se escribe es el motivo de
 * cada consulta, para que quede constancia de quién miró y por qué.
 */
class JefeController extends Controller
{
    private const MESES_QUE_SE_CONSERVAN = 48;

    /**
     * Estados de {@see JornadaService::estadoDe()} que tienen contador en el
     * panel y por los que se puede filtrar la lista.
     *
     * @var list<string>
     */
    private const ESTADOS_CON_CONTADOR = ['trabajando', 'pausa', 'pendiente', 'ausencia', 'sin_empezar_hoy'];

    public function __construct(
        private JornadaService $jornada,
        private RegistroCsv $csv,
        private CupoVacaciones $cupo,
        private CargaAdministrativa $carga,
    ) {}

    public function index(Request $request): View
    {
        $ahora = now();

        $equipo = User::query()->conJornada()
            ->orderByDesc('active')
            ->orderBy('name')
            ->get()
            ->map(fn (User $persona) => [
                'persona' => $persona,
                'horario' => $persona->horarioEn($ahora),
                'estado' => $this->jornada->estadoDe($persona, $ahora),
                'antiguos' => $this->jornada->cierresSinResolverAntiguos($persona, $ahora),
                'desde' => $this->desdeEstado($persona, $ahora),
            ]);
        $filtroEstado = $this->filtroEstado($request);

        return view('jefe.index', [
            'fecha' => Tiempo::fecha($ahora),
            'equipo' => $equipo,
            'personas' => $filtroEstado === null
                ? $equipo
                : $equipo->where('estado.estado', $filtroEstado)->values(),
            'filtroEstado' => $filtroEstado,
            'conCierresAntiguos' => $equipo->where('antiguos', '>', 0)->count(),
            'diasHastaAvisar' => JornadaService::DIAS_HASTA_AVISAR_A_LA_JEFA,
            'cuentas' => [
                'trabajando' => $equipo->where('estado.estado', 'trabajando')->count(),
                'pausa' => $equipo->where('estado.estado', 'pausa')->count(),
                'pendiente' => $equipo->where('estado.estado', 'pendiente')->count(),
                'ausencia' => $equipo->where('estado.estado', 'ausencia')->count(),
                'sin_empezar' => $equipo->where('estado.estado', 'sin_empezar_hoy')->count(),
            ],
        ]);
    }

    /**
     * Estado por el que filtrar la lista del panel, si es uno de los que
     * tienen contador. Cualquier otro valor se ignora y se ve el equipo entero.
     */
    private function filtroEstado(Request $request): ?string
    {
        $estado = $request->query('estado');

        return is_string($estado) && in_array($estado, self::ESTADOS_CON_CONTADOR, true) ? $estado : null;
    }

    private function desdeEstado(User $persona, Carbon $ahora): ?string
    {
        $jornada = Jornada::query()
            ->with('tramos')
            ->where('user_id', $persona->id)
            ->whereDate('work_date', $ahora->toDateString())
            ->first();
        $tramo = $jornada?->tramos
            ->where('tipo', 'trabajo')
            ->sortByDesc('started_at')
            ->first(fn ($tramo) => $tramo->ended_at === null);

        return $tramo?->started_at ? Tiempo::hora($tramo->started_at) : null;
    }

    public function persona(User $user): View
    {
        return view('jefe.persona', [
            'persona' => $user,
            'estado' => $this->jornada->estadoDe($user),
            'antiguos' => $this->jornada->cierresSinResolverAntiguos($user, now()),
            'diasHastaAvisar' => JornadaService::DIAS_HASTA_AVISAR_A_LA_JEFA,
            'horarios' => $user->horarios()->orderByDesc('effective_from')->get(),
            'ausencias' => $user->ausencias()->orderByDesc('absence_date')->get(),
            'consultas' => $this->consultasDe($user, 8),
        ]);
    }

    public function registro(Request $request, User $user): View
    {
        if ($motivo = $this->pedirMotivo($request, $user)) {
            return $motivo;
        }

        $ahora = now();
        $mes = $this->mesElegido($request, $user, $ahora);

        $jornadas = Jornada::query()
            ->with('tramos.correcciones')
            ->where('user_id', $user->id)
            ->whereDate('work_date', '>=', $mes->toDateString())
            ->whereDate('work_date', '<=', $mes->copy()->endOfMonth()->toDateString())
            ->orderByDesc('work_date')
            ->get();
        $cargas = $this->carga->resumen($user, $mes->toDateString(), $mes->copy()->endOfMonth()->toDateString())['dias'];
        $fechasConCarga = $cargas->mapWithKeys(fn ($dia) => [$dia->fecha->toDateString() => true]);
        $fechasConTrabajo = $jornadas
            ->filter(fn (Jornada $jornada) => $jornada->tramos->contains('tipo', 'trabajo'))
            ->mapWithKeys(fn (Jornada $jornada) => [$jornada->work_date->toDateString() => true]);
        $diasSinFichar = Aviso::query()
            ->where('user_id', $user->id)
            ->where('tipo', 'inicio')
            ->whereDate('work_date', '>=', $mes->toDateString())
            ->whereDate('work_date', '>=', $this->jornada->fechaLimiteAvisoSinFichar($ahora))
            ->whereDate('work_date', '<=', $mes->copy()->endOfMonth()->toDateString())
            ->orderByDesc('work_date')
            ->get()
            ->filter(fn (Aviso $aviso) => ! $fechasConTrabajo->has($aviso->work_date->toDateString()) && ! $fechasConCarga->has($aviso->work_date->toDateString()))
            ->unique(fn (Aviso $aviso) => $aviso->work_date->toDateString())
            ->values();
        $filas = $jornadas->map(fn (Jornada $jornada) => $this->csv->presentacion($user, $jornada));
        $registros = $jornadas
            ->values()
            ->map(fn (Jornada $jornada, int $indice) => [
                'jornada' => $jornada,
                'carga' => null,
                'fila' => $filas[$indice],
                'fecha' => $jornada->work_date,
            ])
            ->concat($diasSinFichar->map(fn (Aviso $aviso) => [
                'jornada' => null,
                'carga' => null,
                'fila' => null,
                'fecha' => $aviso->work_date,
            ]))
            ->concat($cargas->map(fn ($dia) => [
                'jornada' => null, 'carga' => $dia, 'fila' => null, 'fecha' => $dia->fecha,
            ]))
            ->sortByDesc('fecha')
            ->values();

        return view('jefe.registro', [
            'persona' => $user,
            'mes' => $mes->format('Y-m'),
            'meses' => $this->mesesDisponibles($user, $ahora),
            'mesTexto' => $mes->copy()->locale('es')->isoFormat('MMMM [de] YYYY'),
            'jornadas' => $jornadas,
            'resumen' => $this->jornada->resumenDelMes($user, $mes, $jornadas, $ahora),
            'semanas' => $this->porSemanas($user, $jornadas, $ahora),
            'filas' => $filas,
            'registros' => $registros,
            'servicio' => $this->jornada,
            'tabla' => $this->csv,
            'explicaciones' => $user->explicaciones()->latest()->limit(10)->get(),
            'consultas' => $this->consultasDe($user, 10),
        ]);
    }

    public function dia(Request $request, Jornada $jornada): View
    {
        $jornada->load('user', 'tramos.correcciones.autor', 'tramos.cerradoPor');
        $persona = $jornada->user;

        if ($motivo = $this->pedirMotivo($request, $persona)) {
            return $motivo;
        }

        return view('jefe.dia', [
            'jornada' => $jornada,
            'persona' => $persona,
            'total' => Tiempo::texto($this->jornada->minutosRegistrados($persona, $jornada)),
            'porEncima' => $this->jornada->porEncimaRegistrado($persona, $jornada),
        ]);
    }

    public function motivo(Request $request, User $user): RedirectResponse
    {
        $datos = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => 'Escribe el motivo de la consulta.',
            'reason.max' => 'El motivo puede tener hasta 500 caracteres.',
        ]);

        Consulta::create([
            'viewer_id' => $request->user()->id,
            'subject_id' => $user->id,
            'reason' => trim($datos['reason']),
        ]);

        $request->session()->put('consulta.'.$user->id, true);

        if ($request->filled('redirect_to')) {
            return redirect()->route('jefe.registro', $user)
                ->with('ok', 'Guardado. Esta consulta queda escrita.');
        }

        return redirect()->intended(route('jefe.registro', $user))
            ->with('ok', 'Guardado. Esta consulta queda escrita.');
    }

    public function csv(Request $request, User $user): StreamedResponse|View
    {
        if ($motivo = $this->pedirMotivo($request, $user)) {
            return $motivo;
        }

        if ($request->filled('mes')) {
            $mes = $this->mesElegido($request, $user, now());

            return $this->csv->descargar($user, $mes->toDateString(), $mes->copy()->endOfMonth()->toDateString());
        }

        return $this->csv->descargar($user);
    }

    public function consultas(Request $request): View
    {
        $persona = $request->query('persona');
        $persona = is_string($persona) ? trim($persona) : '';
        $desde = $request->query('desde');
        $desde = is_string($desde) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) === 1 ? $desde : '';
        $hasta = $request->query('hasta');
        $hasta = is_string($hasta) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta) === 1 ? $hasta : '';
        $mes = $request->query('mes');
        $mes = is_string($mes) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes) === 1 ? $mes : '';
        $mostrarTodas = $request->boolean('todas');
        $limite = $mostrarTodas ? 100 : 3;
        $consultasQuery = Consulta::query()
            ->with('viewer', 'subject')
            ->when($persona !== '', fn ($query) => $query->whereHas('subject', fn ($subject) => $subject->where('name', 'like', '%'.$persona.'%')))
            ->when($desde !== '', fn ($query) => $query->whereDate('created_at', '>=', $desde))
            ->when($hasta !== '', fn ($query) => $query->whereDate('created_at', '<=', $hasta))
            ->when($mes !== '', function ($query) use ($mes): void {
                $inicio = Carbon::createFromFormat('!Y-m', $mes);

                $query->whereBetween('created_at', [
                    $inicio->copy()->startOfMonth(),
                    $inicio->copy()->endOfMonth(),
                ]);
            });
        $consulta = (clone $consultasQuery)
            ->latest('id')
            ->limit($limite)
            ->get();
        $primeraConsulta = Consulta::query()->min('created_at');
        $primerMesConsulta = $primeraConsulta
            ? Carbon::parse($primeraConsulta)->startOfYear()
            : now()->copy()->startOfYear();
        $ultimoMesConsulta = now()->copy()->endOfYear()->startOfMonth();
        $mesesConsultas = collect();
        for ($mesConsulta = $primerMesConsulta->copy(); $mesConsulta->lte($ultimoMesConsulta); $mesConsulta->addMonth()) {
            $mesesConsultas[$mesConsulta->format('Y-m')] = $mesConsulta->copy()->locale('es')->isoFormat('MMMM [de] YYYY');
        }
        $trabajadores = User::query()->conJornada()
            ->where('active', true)
            ->whereNotIn('role', User::ROLES_JEFE)
            ->orderBy('name')
            ->get(['name']);

        return view('jefe.consultas', [
            'consultas' => $consulta,
            'mostrarTodas' => $mostrarTodas,
            'hayMasConsultas' => $mostrarTodas || (clone $consultasQuery)->count() > 3,
            'filtrosConsultas' => compact('persona', 'desde', 'hasta', 'mes'),
            'mesesConsultas' => $mesesConsultas,
            'trabajadores' => $trabajadores,
        ]);
    }

    public function decidirVacaciones(Request $request, SolicitudVacaciones $solicitud): RedirectResponse
    {
        $datos = $request->validate([
            'decision' => ['required', 'in:aprobada,rechazada'],
        ], [
            'decision.required' => 'Elige si la apruebas o la rechazas.',
            'decision.in' => 'Elige si la apruebas o la rechazas.',
        ]);

        if ($solicitud->estado !== SolicitudVacaciones::PENDIENTE) {
            return back()->with('aviso', 'Esa solicitud ya está revisada.');
        }

        try {
            DB::transaction(function () use ($request, $solicitud, $datos) {
                $autor = User::query()->whereKey($solicitud->user_id)->lockForUpdate()->firstOrFail();
                $actual = SolicitudVacaciones::query()->lockForUpdate()->findOrFail($solicitud->id);

                if ($actual->estado !== SolicitudVacaciones::PENDIENTE) {
                    throw new ReglaJornada('Esa solicitud ya está revisada.');
                }

                if ($datos['decision'] === SolicitudVacaciones::RECHAZADA) {
                    $actual->update([
                        'estado' => SolicitudVacaciones::RECHAZADA,
                        'decidida_por' => $request->user()->id,
                        'decidida_at' => now(),
                    ]);

                    return;
                }

                if (! $autor->estaEnPlantillaEl($actual->starts_on) || ! $autor->estaEnPlantillaEl($actual->ends_on)) {
                    throw new ReglaJornada('Las fechas deben estar dentro del periodo laboral de la persona.');
                }
                $this->cupo->asegurarCobertura($autor, $actual->starts_on, $actual->ends_on);
                $cuentan = $this->cupo->queCuentan($autor, $actual->starts_on, $actual->ends_on);
                if ($cuentan->isEmpty()) {
                    throw new ReglaJornada('Esos días no cuentan: son fin de semana o festivo.');
                }
                if (! $this->cupo->cabe($autor, $actual->starts_on, $actual->ends_on, $actual->id)) {
                    throw new ReglaJornada('El saldo disponible es insuficiente o no está confirmado.');
                }

                foreach ($cuentan as $fecha) {
                    Ausencia::create([
                        'user_id' => $actual->user_id,
                        'absence_date' => $fecha,
                        'tipo' => 'vacaciones',
                    ]);
                    $this->carga->cancelarPorAusencia($request->user(), $autor, $fecha, 'Vacaciones aprobadas.');
                }

                $actual->update([
                    'estado' => SolicitudVacaciones::APROBADA,
                    'decidida_por' => $request->user()->id,
                    'decidida_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return back()->with('aviso', 'Esos días ya tienen una ausencia.');
        } catch (ReglaJornada $e) {
            return back()->with('aviso', $e->getMessage());
        }

        $solicitud->refresh();

        if ($solicitud->estado !== $datos['decision']) {
            return back()->with('aviso', 'Esa solicitud ya está revisada.');
        }

        if ($solicitud->estado === SolicitudVacaciones::RECHAZADA) {
            return back()->with('ok', 'Rechazada. Esas vacaciones no se añaden al calendario.');
        }

        return back()->with('ok', 'Aprobada. Esas vacaciones ya están en su calendario.');
    }

    /**
     * Pide el motivo antes de enseñar el registro de una persona. Una vez
     * escrito, vale para esa persona durante la sesión.
     */
    private function pedirMotivo(Request $request, User $persona): ?View
    {
        if ($request->session()->get('consulta.'.$persona->id)) {
            return null;
        }

        $request->session()->put('url.intended', url()->full());

        return view('jefe.motivo', ['persona' => $persona]);
    }

    /**
     * Las jornadas del mes agrupadas por semana, con lo registrado en cada una.
     *
     * @param  Collection<int, Jornada>  $jornadas
     * @return Collection<int, array{lunes: Carbon, domingo: Carbon, minutos: int, jornadas: Collection<int, Jornada>}>
     */
    private function porSemanas(User $persona, Collection $jornadas, Carbon $ahora): Collection
    {
        return $jornadas
            ->groupBy(fn (Jornada $jornada) => $jornada->work_date->copy()->startOfWeek(Carbon::MONDAY)->toDateString())
            ->map(fn (Collection $grupo, string $lunes) => [
                'lunes' => Carbon::parse($lunes),
                'domingo' => Carbon::parse($lunes)->endOfWeek(Carbon::SUNDAY),
                'minutos' => $grupo->sum(fn (Jornada $jornada) => $this->jornada->minutosRegistrados($persona, $jornada, $ahora)),
                'jornadas' => $grupo,
            ])
            ->values();
    }

    /**
     * @return Collection<int, Consulta>
     */
    private function consultasDe(User $persona, int $limite)
    {
        return Consulta::query()
            ->with('viewer')
            ->where('subject_id', $persona->id)
            ->latest()
            ->limit($limite)
            ->get();
    }

    /**
     * El mes pedido, si es uno en el que la persona tiene registro. Si no, el actual.
     */
    private function mesElegido(Request $request, User $persona, Carbon $ahora): Carbon
    {
        $actual = $ahora->copy()->startOfMonth();
        $pedido = (string) $request->query('mes', '');

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $pedido)) {
            return $actual;
        }

        $mes = Carbon::createFromFormat('!Y-m', $pedido, config('app.timezone'));

        return array_key_exists($mes->format('Y-m'), $this->mesesDisponibles($persona, $ahora)) ? $mes : $actual;
    }

    /**
     * Meses con registro posible, del más reciente al más antiguo. Se llega
     * hasta cuatro años atrás, el plazo de conservación del artículo 34.9.
     *
     * @return array<string, string>
     */
    private function mesesDisponibles(User $persona, Carbon $ahora): array
    {
        $primero = ($persona->starts_on ?? $ahora)->copy()->startOfMonth();
        $meses = [];

        for ($mes = $ahora->copy()->startOfMonth(); $mes->gte($primero) && count($meses) < self::MESES_QUE_SE_CONSERVAN; $mes->subMonthNoOverflow()) {
            $meses[$mes->format('Y-m')] = $mes->copy()->locale('es')->isoFormat('MMMM [de] YYYY');
        }

        return $meses ?: [$ahora->format('Y-m') => $ahora->copy()->locale('es')->isoFormat('MMMM [de] YYYY')];
    }
}
