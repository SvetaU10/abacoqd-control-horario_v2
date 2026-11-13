<?php

namespace App\Http\Controllers;

use App\DuracionEstancia;
use App\Exceptions\ReglaJornada;
use App\Models\Ausencia;
use App\Models\CargaAdministrativaDia;
use App\Models\Centro;
use App\Models\Consulta;
use App\Models\EventoAusencia;
use App\Models\EventoCuenta;
use App\Models\Horario;
use App\Models\ImportacionCalendario;
use App\Models\InterrupcionPlantilla;
use App\Models\Jornada;
use App\Models\Tramo;
use App\Models\VigenciaCalendario;
use App\Models\User;
use App\Services\AsignacionesCentro;
use App\Services\CalendarioMunicipal;
use App\Services\CargaAdministrativa;
use App\Services\CatalogoMunicipios;
use App\Services\CupoVacaciones;
use App\Services\JornadaService;
use App\Services\NagerFestivos;
use App\Services\VigenciasCalendario;
use App\Support\RejillaCalendario;
use App\TipoJornada;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class EquipoController extends Controller
{
    public function __construct(
        private JornadaService $jornada,
        private NagerFestivos $festivos,
        private CargaAdministrativa $carga,
        private CupoVacaciones $cupo,
    ) {}

    public function index(Request $request): View
    {
        $hoy = now();
        $mostrarTodas = $request->boolean('todas_personas');
        $verBajas = $request->boolean('bajas');
        $personasQuery = User::query()->conJornada()
            ->with(['horarios', 'sede'])
            ->when($verBajas, fn ($query) => $query->deBajaEl($hoy), fn ($query) => $query->conAccesoEl($hoy))
            ->when($verBajas, fn ($query) => $query->orderByDesc('baja_on'))
            ->orderBy('name');

        return view('equipo.index', [
            'personas' => (clone $personasQuery)
                ->when(! $mostrarTodas && ! $verBajas, fn ($query) => $query->limit(8))
                ->get(),
            'mostrarTodas' => $mostrarTodas,
            'verBajas' => $verBajas,
            'totalPersonas' => (clone $personasQuery)->count(),
            'totalBajas' => User::query()->conJornada()->deBajaEl($hoy)->count(),
            'hoy' => $hoy,
            'esJefa' => true,
        ]);
    }

    public function crearCentro(Request $request, CatalogoMunicipios $catalogo): RedirectResponse
    {
        $datos = $this->datosCentro($request, $catalogo);

        Centro::create($datos);

        return back()->with('ok', 'Sede guardada. Ya puedes asignarla a una persona.');
    }

    public function actualizarCentro(Request $request, Centro $centro, CalendarioMunicipal $calendario, CatalogoMunicipios $catalogo, VigenciasCalendario $vigencias): RedirectResponse
    {
        $datos = $this->datosCentro($request, $catalogo, $centro);

        $cambiaTerritorio = mb_strtolower(trim($centro->municipality)) !== mb_strtolower(trim($datos['municipality']))
            || mb_strtolower(trim($centro->community)) !== mb_strtolower(trim($datos['community']))
            || (array_key_exists('municipio_ine', $datos) && $centro->municipio_ine !== $datos['municipio_ine']);

        $desde = null;
        $anios = collect();
        if ($cambiaTerritorio && ! empty($datos['municipio_ine'])) {
            $desde = $this->fechaDeCalendario($request);
            $anio = (int) substr($desde, 0, 4);
            $anios = collect([$anio])
                ->merge(ImportacionCalendario::query()->where('centro_id', $centro->id)->where('anio', '>=', $anio)->pluck('anio'))
                ->merge(VigenciaCalendario::query()->where('centro_id', $centro->id)->where('anio', '>=', $anio)->pluck('anio'))
                ->map(fn ($valor): int => (int) $valor)
                ->unique()
                ->sort()
                ->values();
        }

        DB::transaction(function () use ($centro, $datos, $calendario, $request, $cambiaTerritorio, $vigencias, $desde): void {
            if ($cambiaTerritorio) {
                $vigencias->conservarManuales($centro);
            }
            $centro->update($datos);
            $centro->users()->update(['municipality' => $datos['municipality']]);
            if ($cambiaTerritorio) {
                $calendario->invalidarCentro($centro, $request->user(), $desde ? Carbon::parse($desde) : null);
            }
        });

        $resultados = [];
        if ($desde !== null) {
            foreach ($anios as $anioCarga) {
                $inicio = $anioCarga === (int) substr($desde, 0, 4) ? $desde : sprintf('%04d-01-01', $anioCarga);
                try {
                    $resultado = $calendario->cargar($centro, $anioCarga, $datos['municipio_ine'], $request->user(), desde: $inicio);
                    if ($resultado->puedeConfirmarse()) {
                        $resultado = $calendario->confirmar($resultado, $request->user());
                    }
                } catch (InvalidArgumentException $error) {
                    return back()->with('aviso', 'Sede actualizada. '.$error->getMessage());
                }
                $resultados[] = $resultado;
            }
        }

        $fallo = collect($resultados)->first(fn (ImportacionCalendario $resultado): bool => ! in_array($resultado->estado, ['cargado', 'actualizado', 'sin_cambios'], true));
        if ($desde !== null && $fallo === null && $resultados !== []) {
            return redirect()
                ->route('gestion.calendario.mostrar', ['centro' => $centro, 'anio' => (int) substr($desde, 0, 4)])
                ->with('ok', 'Sede actualizada. Los festivos de '.$datos['municipality'].' están guardados desde el '.Carbon::parse($desde)->format('d/m/Y').'.');
        }
        if ($fallo !== null) {
            return back()->with('aviso', 'Sede actualizada, pero no se han podido guardar los festivos desde el '.Carbon::parse($desde)->format('d/m/Y').'.');
        }

        return back()->with('ok', $cambiaTerritorio
            ? 'Sede actualizada. Carga de nuevo los calendarios del municipio para confirmar sus festivos.'
            : 'Sede actualizada. Las personas vinculadas usarán el nuevo municipio.');
    }

    public function actualizarMinimo(Request $request, Centro $centro): RedirectResponse
    {
        $minimo = (int) $request->validate([
            'minimo_presencia' => ['required', 'integer', 'between:1,99'],
        ], [
            'minimo_presencia.required' => 'Indica cuántas personas tienen que quedar en la sede.',
            'minimo_presencia.integer' => 'Indica un mínimo entre 1 y 99.',
            'minimo_presencia.between' => 'Indica un mínimo entre 1 y 99.',
        ])['minimo_presencia'];
        $centro->update(['minimo_presencia' => $minimo]);

        return back()->with('ok', 'El mínimo de '.$centro->name.' es '.$minimo.($minimo === 1 ? ' persona.' : ' personas.'));
    }

    private function fechaDeCalendario(Request $request): string
    {
        $fecha = $request->validate([
            'vigente_desde' => ['required', 'date_format:Y-m-d'],
        ], [
            'vigente_desde.required' => 'Indica desde qué día se usará este municipio.',
            'vigente_desde.date_format' => 'Indica una fecha válida.',
        ])['vigente_desde'];
        $anio = (int) substr($fecha, 0, 4);
        $mes = (int) substr($fecha, 5, 2);
        $dia = (int) substr($fecha, 8, 2);
        if ($anio < 2020 || $anio > now()->year + 5 || ! checkdate($mes, $dia, $anio)) {
            throw ValidationException::withMessages([
                'vigente_desde' => 'Elige una fecha entre 2020 y '.(now()->year + 5).'.',
            ]);
        }

        return $fecha;
    }

    /** @return array<string,mixed> */
    private function datosCentro(Request $request, CatalogoMunicipios $catalogo, ?Centro $centro = null): array
    {
        $datos = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('centros', 'name')->ignore($centro)],
            'municipio_ine' => ['nullable', 'string', 'regex:/^\d{5}$/D', 'required_without:municipality'],
            'municipality' => ['nullable', 'required_without:municipio_ine', 'string', 'max:160'],
            'community' => ['nullable', 'required_without:municipio_ine', 'string', 'max:120'],
        ], [
            'name.required' => 'Escribe el nombre de la sede.',
            'name.unique' => 'Ese nombre de sede ya existe.',
            'municipio_ine.required_without' => 'Selecciona un municipio del catálogo.',
            'municipio_ine.regex' => 'Selecciona un municipio válido del catálogo.',
            'municipality.required_without' => 'Escribe el municipio.',
            'community.required_without' => 'Escribe la autonomía.',
        ]);
        if (! empty($datos['municipio_ine'])) {
            try {
                $municipio = $catalogo->resolver($datos['municipio_ine']);
            } catch (InvalidArgumentException $error) {
                throw ValidationException::withMessages(['municipio_ine' => $error->getMessage()]);
            }

            return [
                'name' => $datos['name'], 'municipio_ine' => $municipio['ine'],
                'municipality' => $municipio['nombre'], 'province' => $municipio['provincia'],
                'province_code' => $municipio['provincia_codigo'], 'community' => $municipio['comunidad'],
                'comunidad_codigo' => $municipio['comunidad_codigo'],
            ];
        }
        unset($datos['municipio_ine']);
        if ($centro === null || $centro->municipality !== $datos['municipality'] || $centro->community !== $datos['community']) {
            $datos += ['municipio_ine' => null, 'province' => null, 'province_code' => null, 'comunidad_codigo' => null];
        }

        return $datos;
    }

    public function create(Request $request): View
    {
        $tipo = TipoJornada::tryFrom((string) $request->old('tipo', $request->query('tipo', '')));
        $startsOn = $this->fechaConsulta($request, 'starts_on') ?? now()->toDateString();
        $duracion = DuracionEstancia::tryFrom((string) $request->old('duracion', $request->query('duracion', '')));
        $endsOnInput = $this->fechaConsulta($request, 'ends_on');
        $endsOn = $duracion?->fechaFin(Carbon::parse($startsOn), $endsOnInput);
        $periodoListo = $duracion !== null
            && ($duracion !== DuracionEstancia::HastaElDia || $endsOn !== null);

        $anioInicio = Carbon::parse($startsOn)->year;
        $anioFin = $endsOn ? Carbon::parse($endsOn)->year : max(now()->year + 1, $anioInicio);
        $navegaAnios = ! $periodoListo || $duracion === DuracionEstancia::Indefinido;
        $anio = (int) $request->query('anio', $periodoListo ? $anioInicio : now()->year);
        if ($periodoListo && $navegaAnios) {
            $anio = max($anioInicio, min($anio, $anioFin));
        } elseif ($periodoListo) {
            $anio = $anioInicio;
        } elseif ($anio < 2020 || $anio > now()->year + 1) {
            $anio = now()->year;
        }

        $nombreSede = $request->old('centro', $request->query('centro'));
        $sede = Centro::query()
            ->when($nombreSede, fn ($query) => $query->where('name', $nombreSede))
            ->first() ?? Centro::query()->orderBy('name')->firstOrFail();
        $aniosFestivos = $periodoListo && $endsOn
            ? range($anioInicio, $anioFin)
            : [$anio];
        $festivos = collect();
        foreach (array_unique($aniosFestivos) as $anioFestivo) {
            $festivos = $festivos->concat($this->festivos->delAnio($sede, $anioFestivo));
        }
        $consulta = array_filter([
            'tipo' => $tipo?->value,
            'starts_on' => $startsOn,
            'duracion' => $duracion?->value,
            'ends_on' => $duracion === DuracionEstancia::HastaElDia ? $endsOnInput : null,
            'centro' => $sede->name,
        ]);
        $meses = RejillaCalendario::meses($anio, $festivos, $periodoListo ? $startsOn : null, $periodoListo ? $endsOn : null, $periodoListo);
        $aniosMeses = collect($meses)->pluck('anio')->unique();

        return view('equipo.create', [
            'esJefa' => true,
            'anio' => $anio,
            'anioSiguiente' => $anio + 1,
            'puedeAvanzar' => $navegaAnios && $anio < ($periodoListo ? $anioFin : now()->year + 1),
            'puedeRetroceder' => $navegaAnios && $anio > ($periodoListo ? $anioInicio : 2020),
            'navegaAnios' => $navegaAnios,
            'sede' => $sede,
            'sedes' => Centro::query()->orderBy('name')->get(),
            'centro' => $sede->municipio(),
            'festivos' => $festivos,
            'sinCalendario' => $festivos->isEmpty(),
            'meses' => $meses,
            'muestraAnioMes' => $aniosMeses->count() > 1,
            'tipo' => $tipo,
            'tipos' => TipoJornada::cases(),
            'duracion' => $duracion,
            'duraciones' => DuracionEstancia::cases(),
            'startsOn' => $startsOn,
            'endsOn' => $endsOnInput,
            'finPeriodo' => $endsOn,
            'periodoListo' => $periodoListo,
            'consulta' => $consulta,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validarPersona($request, true);
        $tipo = TipoJornada::from($datos['tipo']);
        $sede = Centro::query()->where('name', $datos['centro'])->firstOrFail();
        $duracion = DuracionEstancia::from($datos['duracion']);
        $endsOn = $duracion->fechaFin(Carbon::parse($datos['starts_on']), $datos['ends_on'] ?? null);
        $datos = array_merge($datos, $tipo->horas());

        try {
            $persona = DB::transaction(function () use ($datos, $tipo, $sede, $endsOn) {
                $persona = User::create([
                    'name' => $datos['name'],
                    'email' => $datos['email'],
                    'password' => $datos['password'],
                    'role' => 'trabajador',
                    'active' => true,
                    'starts_on' => $datos['starts_on'],
                    'ends_on' => $endsOn,
                    'municipality' => $sede->municipio(),
                    'centro_id' => $sede->id,
                ]);
                $this->guardarHorario($persona, $datos, $datos['starts_on'], $tipo);

                return $persona;
            });
        } catch (ReglaJornada $e) {
            return back()->with('aviso', $e->getMessage())->withInput();
        }

        return redirect()->route('equipo.index')->with('ok', 'Guardado. '.$datos['name'].' ya puede entrar.');
    }

    public function show(Request $request, User $user): View
    {
        $hoy = now()->startOfDay();

        return view('equipo.show', [
            'persona' => $user,
            'horarios' => $user->horarios()->orderByDesc('effective_from')->get(),
            'ausencias' => $user->ausencias()->orderByDesc('absence_date')->get(),
            'ausenciasAnuladas' => EventoAusencia::query()->with('actor')->where('user_id', $user->id)->latest('id')->get(),
            'consultas' => Consulta::query()->with('viewer')->where('subject_id', $user->id)->latest()->limit(8)->get(),
            'centros' => Centro::query()->orderBy('name')->get(),
            'eventosCuenta' => EventoCuenta::query()->with('actor')->where('user_id', $user->id)->latest('id')->get(),
            'hoy' => $hoy,
            'deBaja' => $user->estaDeBajaEl($hoy),
            'bajaProgramada' => $user->tieneBajaProgramadaEl($hoy),
            'bajaAnulable' => $user->bajaAnulableEl($hoy),
            'bajaSinFecha' => $user->bajaSinFecha(),
            'esUnoMismo' => (int) $request->user()->id === (int) $user->id,
            'abrirReincorporar' => $request->boolean('reincorporar'),
            'esJefa' => true,
        ]);
    }

    /**
     * Corrige el nombre y el fin de contrato. El inicio, la sede, el horario y
     * el correo no se tocan aquí: cambiarlos reescribiría días ya registrados.
     */
    public function edit(User $user): View
    {
        return view('equipo.edit', [
            'persona' => $user,
            'horario' => $user->horarioEn(now()),
            'esJefa' => true,
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $reglasFin = ['nullable', 'date_format:Y-m-d'];
        if ($user->starts_on) {
            $reglasFin[] = 'after_or_equal:'.$user->starts_on->toDateString();
        }
        $datos = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'ends_on' => $reglasFin,
            'motivo' => ['required', 'string', 'min:3', 'max:300'],
        ], [
            'name.required' => 'Escribe el nombre.',
            'name.min' => 'El nombre necesita al menos 2 caracteres.',
            'name.max' => 'El nombre puede tener hasta 80 caracteres.',
            'ends_on.date_format' => 'Elige una fecha válida para el fin de contrato.',
            'ends_on.after_or_equal' => 'El fin de contrato no puede ser anterior al alta.',
            'motivo.required' => 'Explica el motivo del cambio.',
            'motivo.min' => 'El motivo necesita al menos 3 caracteres.',
            'motivo.max' => 'El motivo puede tener hasta 300 caracteres.',
        ]);

        try {
            $hueco = DB::transaction(function () use ($request, $user, $datos): ?array {
                $persona = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $antes = ['name' => $persona->name, 'ends_on' => $persona->ends_on?->toDateString()];
                $despues = ['name' => $datos['name'], 'ends_on' => $datos['ends_on'] ?? null];
                if ($antes === $despues) {
                    throw new ReglaJornada('No hay cambios que guardar.');
                }
                if ($despues['ends_on'] !== null && $despues['ends_on'] !== $antes['ends_on']) {
                    $ultimo = $this->ultimoDiaRegistrado($persona, $despues['ends_on']);
                    if ($ultimo) {
                        throw new ReglaJornada('Hay registro después de ese fin de contrato (último día: '.$ultimo->format('d/m/Y').'). Elige una fecha igual o posterior.');
                    }
                }
                $hueco = $this->huecoDeContratoVencido($persona, $antes['ends_on'], $despues['ends_on']);

                $persona->update($despues);
                $evento = EventoCuenta::create([
                    'user_id' => $persona->id, 'actor_id' => $request->user()->id, 'tipo' => EventoCuenta::EDICION,
                    'detalles' => ['antes' => $antes, 'despues' => $despues] + ($hueco ? ['interrupcion' => $hueco] : []),
                    'motivo' => $datos['motivo'], 'created_at' => now(),
                ]);
                if ($hueco) {
                    InterrupcionPlantilla::create($hueco + ['user_id' => $persona->id, 'evento_cuenta_id' => $evento->id]);
                }

                return $hueco;
            });
        } catch (ReglaJornada $e) {
            return back()->with('aviso', $e->getMessage())->withInput();
        }

        $mensaje = 'Alta corregida. El cambio queda en el historial de la cuenta.';
        if ($hueco) {
            $mensaje .= ' Del '.Carbon::parse($hueco['desde'])->format('d/m/Y').' al '.Carbon::parse($hueco['hasta'])->format('d/m/Y').' no tenía contrato: esos días no cuentan como previstos.';
        }

        return redirect()->route('equipo.show', $user)->with('ok', $mensaje);
    }

    /**
     * Al ampliar un contrato que ya terminó, los días entre el fin anterior y
     * ayer no pasan a ser laborables: la persona no podía fichar en ellos. Quedan
     * como interrupción. Hoy sí cuenta, porque puede fichar en cuanto se guarda.
     *
     * @return array{desde: string, hasta: string}|null
     */
    private function huecoDeContratoVencido(User $persona, ?string $finAntes, ?string $finDespues): ?array
    {
        $hoy = now()->toDateString();
        if ($finAntes === null || $finAntes >= $hoy || ($finDespues !== null && $finDespues <= $finAntes)) {
            return null;
        }

        $desde = Carbon::parse($finAntes)->addDay()->toDateString();
        $ayer = now()->subDay()->toDateString();
        $hasta = $finDespues === null ? $ayer : min($finDespues, $ayer);
        // Una reincorporación puede haber dejado ya esos días como interrupción.
        foreach ($persona->interrupcionesPlantilla()->get() as $existente) {
            if ($existente->cubre($desde)) {
                $desde = $existente->hasta->copy()->addDay()->toDateString();
            }
        }
        if ($desde > $hasta) {
            return null;
        }

        $ultimo = $this->ultimoDiaRegistrado($persona, Carbon::parse($desde)->subDay()->toDateString(), $hasta);
        if ($ultimo) {
            throw new ReglaJornada('Hay registro el '.$ultimo->format('d/m/Y').', después del fin de contrato anterior. Revísalo antes de ampliar el contrato.');
        }

        return ['desde' => $desde, 'hasta' => $hasta];
    }

    public function horario(Request $request, User $user): RedirectResponse
    {
        $datos = $this->validarHorario($request);
        $datos['effective_from'] = $request->validate([
            'effective_from' => ['required', 'date', 'after_or_equal:today'],
        ], [
            'effective_from.required' => 'Elige desde qué día vale el horario.',
            'effective_from.after_or_equal' => 'El horario nuevo vale desde hoy o un día posterior. Los días anteriores se conservan.',
        ])['effective_from'];

        try {
            $this->guardarHorario($user, $datos, $datos['effective_from'], null);
            $this->jornada->excluirAvisosDeHorario($user, $datos['effective_from']);
        } catch (ReglaJornada $e) {
            return back()->with('aviso', $e->getMessage())->withInput();
        }

        return back()->with('ok', 'Guardado. El horario vale desde el '.$datos['effective_from'].'. Los avisos desde ese día no cuentan. Los anteriores se conservan.');
    }

    public function centro(Request $request, User $user, AsignacionesCentro $asignaciones): RedirectResponse
    {
        $datos = $request->validate([
            'centro' => ['required', 'string', 'exists:centros,name'],
            'effective_from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:today'],
        ], [
            'centro.required' => 'Elige una sede.',
            'centro.exists' => 'Elige una sede municipal válida.',
            'effective_from.date_format' => 'Elige una fecha válida.',
            'effective_from.after_or_equal' => 'El cambio de sede se aplica desde hoy. Los días anteriores se conservan.',
            'effective_from.before_or_equal' => 'El cambio de sede se aplica desde hoy. Todavía no se puede programar para una fecha futura.',
        ]);
        $centro = Centro::query()->where('name', $datos['centro'])->firstOrFail();
        $asignaciones->cambiar($user, $centro, $request->user(), Carbon::parse($datos['effective_from'] ?? now()->toDateString()));

        return back()->with('ok', 'Guardado. La sede de '.$user->name.' es '.$centro->municipality.' desde hoy. Los días anteriores conservan su sede.');
    }

    public function ausencia(Request $request, User $user): RedirectResponse
    {
        $datos = $request->validate([
            'absence_date' => ['required', 'date'],
            'tipo' => ['required', 'in:vacaciones,permiso'],
        ], [
            'absence_date.required' => 'Elige el día.',
            'tipo.required' => 'Elige vacaciones o permiso.',
        ]);

        try {
            DB::transaction(function () use ($request, $user, $datos): void {
                $persona = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $dia = Carbon::parse($datos['absence_date'])->startOfDay();
                if (! $persona->registraJornada()) {
                    throw new ReglaJornada('Esta cuenta técnica no admite ausencias laborales.');
                }
                if (! $persona->estaEnPlantillaEl($dia)) {
                    throw new ReglaJornada('La fecha debe estar dentro del periodo laboral de la persona.');
                }
                if ($datos['tipo'] === 'vacaciones') {
                    $this->cupo->asegurarCobertura($persona, $dia, $dia);
                }
                if ($datos['tipo'] === 'vacaciones' && $dia->greaterThanOrEqualTo(now()->startOfDay())
                    && ($this->cupo->restantes($persona, $dia->year) === null || ! $this->cupo->cabe($persona, $dia, $dia))) {
                    throw new ReglaJornada('El saldo disponible es insuficiente o no está confirmado.');
                }
                Ausencia::create([
                    'user_id' => $persona->id,
                    'absence_date' => $datos['absence_date'],
                    'tipo' => $datos['tipo'],
                ]);
                $this->carga->cancelarPorAusencia($request->user(), $persona, $dia->toDateString(), 'Ausencia registrada por el responsable.');
            });
        } catch (UniqueConstraintViolationException) {
            return back()->with('aviso', 'Ese día ya tiene una ausencia.');
        } catch (ReglaJornada $e) {
            return back()->with('aviso', $e->getMessage());
        }

        if ($datos['tipo'] === 'vacaciones' && Carbon::parse($datos['absence_date'])->startOfDay()->lt(now()->startOfDay())) {
            return back()->with('ok', 'Ausencia histórica guardada. Revisa y confirma de nuevo el saldo de vacaciones.');
        }

        return back()->with('ok', 'Guardado. Ese día no se espera fichaje.');
    }

    public function anularAusencia(Request $request, User $user, Ausencia $ausencia): RedirectResponse
    {
        abort_unless((int) $ausencia->user_id === (int) $user->id, 404);
        $datos = $request->validate([
            'motivo' => ['required', 'string', 'max:500'],
        ], [
            'motivo.required' => 'Explica el motivo de la corrección.',
            'motivo.max' => 'El motivo puede tener hasta 500 caracteres.',
        ]);

        try {
            $sinVinculo = DB::transaction(function () use ($request, $user, $ausencia, $datos): bool {
                $persona = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $actual = Ausencia::query()->whereKey($ausencia->id)->where('user_id', $persona->id)->lockForUpdate()->firstOrFail();
                $fecha = $actual->absence_date->toDateString();
                if (Jornada::query()->where('user_id', $persona->id)->whereDate('work_date', $fecha)
                    ->whereHas('tramos', fn ($q) => $q->where('tipo', 'trabajo'))->exists()) {
                    throw new ReglaJornada('Existe un fichaje real ese día. Revisa el conflicto sin borrarlo.');
                }

                $candidatas = CargaAdministrativaDia::query()->with('eventos')->where('user_id', $persona->id)
                    ->whereDate('fecha', $fecha)->where('estado', 'anulada_ausencia')->lockForUpdate()->get();
                $carga = $candidatas->first(function (CargaAdministrativaDia $dia) use ($actual): bool {
                    $evento = $dia->eventos->sortByDesc('id')->first();

                    return $evento && $evento->tipo === 'anulada_ausencia'
                        && (int) ($evento->detalles['ausencia_id'] ?? 0) === (int) $actual->id;
                });
                $sinVinculo = $carga === null && $candidatas->contains(function (CargaAdministrativaDia $dia): bool {
                    $evento = $dia->eventos->sortByDesc('id')->first();

                    return $evento && $evento->tipo === 'anulada_ausencia' && ! isset($evento->detalles['ausencia_id']);
                });
                EventoAusencia::create([
                    'user_id' => $persona->id, 'actor_id' => $request->user()->id,
                    'ausencia_original_id' => $actual->id, 'absence_date' => $fecha,
                    'tipo' => $actual->tipo, 'ausencia_creada_at' => $actual->created_at,
                    'motivo' => trim($datos['motivo']), 'carga_dia_id' => $carga?->id, 'created_at' => now(),
                ]);
                $actual->delete();
                if ($carga) {
                    $this->carga->restaurarTrasCorregirAusencia($request->user(), $persona, $fecha, trim($datos['motivo']), $carga->id);
                }

                return $sinVinculo;
            }, 3);
        } catch (ReglaJornada $e) {
            return back()->with('aviso', $e->getMessage())->withInput();
        }

        return $sinVinculo
            ? back()->with('aviso', 'Ausencia anulada. Hay una carga antigua sin vínculo verificable; revísala antes de restaurarla.')
            : back()->with('ok', 'Ausencia anulada. La corrección queda en el historial.');
    }

    /**
     * Baja con fecha: último día en la empresa. Si es hoy o un día pasado, el
     * acceso se cierra al guardar; si es futura, se conserva hasta el final de ese día.
     */
    public function baja(Request $request, User $user): RedirectResponse
    {
        if ((int) $request->user()->id === (int) $user->id) {
            return back()->with('aviso', 'No puedes darte de baja a ti mismo.');
        }

        $hoy = now()->startOfDay();
        // Una cuenta cerrada antes de que la baja tuviera fecha solo recibe la fecha:
        // ya no tiene acceso, así que el último día es hoy o uno pasado.
        $soloFecha = $user->bajaSinFecha();
        $mensajeSinFecha = 'La cuenta ya está cerrada: el último día tiene que ser hoy o un día pasado.';
        $maximo = $soloFecha ? $hoy->toDateString() : $hoy->copy()->addYear()->toDateString();
        $reglasFecha = ['required', 'date_format:Y-m-d', 'before_or_equal:'.$maximo];
        if ($user->starts_on) {
            $reglasFecha[] = 'after_or_equal:'.$user->starts_on->toDateString();
        }
        $datos = $request->validate([
            'fecha_baja' => $reglasFecha,
            'motivo_baja' => ['required', 'string', 'min:3', 'max:300'],
        ], [
            'fecha_baja.required' => 'Elige el último día en la empresa.',
            'fecha_baja.date_format' => 'Elige una fecha de baja válida.',
            'fecha_baja.before_or_equal' => $soloFecha ? $mensajeSinFecha : 'La baja se puede programar como mucho con un año de antelación.',
            'fecha_baja.after_or_equal' => 'La baja no puede ser anterior al alta.',
            'motivo_baja.required' => 'Explica el motivo de la baja.',
            'motivo_baja.min' => 'El motivo necesita al menos 3 caracteres.',
            'motivo_baja.max' => 'El motivo puede tener hasta 300 caracteres.',
        ]);
        $fecha = $datos['fecha_baja'];

        try {
            $resultado = DB::transaction(function () use ($request, $user, $datos, $fecha, $hoy, $mensajeSinFecha): string {
                $persona = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $soloFecha = $persona->bajaSinFecha();
                if ($soloFecha && $fecha > $hoy->toDateString()) {
                    throw new ReglaJornada($mensajeSinFecha);
                }
                if (! $soloFecha && $persona->tieneBajaProgramadaEl($hoy)) {
                    throw new ReglaJornada('Ya tiene una baja prevista para el '.$persona->baja_on->format('d/m/Y').'. Anúlala antes de elegir otra fecha.');
                }
                if (! $soloFecha && $persona->estaDeBajaEl($hoy)) {
                    throw new ReglaJornada($persona->name.' ya está de baja.');
                }
                $ultimo = $this->ultimoDiaRegistrado($persona, $fecha);
                if ($ultimo) {
                    throw new ReglaJornada('Hay registro después de esa fecha (último día: '.$ultimo->format('d/m/Y').'). La baja no puede ser anterior.');
                }

                $cierraYa = $fecha <= $hoy->toDateString();
                if ($cierraYa && ! $soloFecha) {
                    // Sin acceso no podría cerrar el tramo y sus horas de ese día se perderían.
                    $abierta = Jornada::query()->where('user_id', $persona->id)
                        ->whereDate('work_date', '<=', $fecha)
                        ->whereHas('tramos', fn ($query) => $query->whereNull('ended_at'))
                        ->orderBy('work_date')
                        ->value('work_date');
                    if ($abierta) {
                        throw new ReglaJornada($persona->name.' tiene un tramo sin cerrar el '.Carbon::parse($abierta)->format('d/m/Y').'. Tiene que cerrarlo antes de la baja: después ya no podrá entrar a hacerlo.');
                    }
                }

                $antes = ['active' => (bool) $persona->active, 'baja_on' => null];
                $despues = ['active' => ! $soloFecha && ! $cierraYa, 'baja_on' => $fecha];
                $persona->update($despues);
                EventoCuenta::create([
                    'user_id' => $persona->id, 'actor_id' => $request->user()->id, 'tipo' => EventoCuenta::BAJA,
                    'detalles' => ['antes' => $antes, 'despues' => $despues],
                    'motivo' => $datos['motivo_baja'], 'created_at' => now(),
                ]);

                return $soloFecha ? 'fecha' : ($cierraYa ? 'cerrada' : 'prevista');
            });
        } catch (ReglaJornada $e) {
            return back()->with('aviso', $e->getMessage())->withInput();
        }

        $dia = Carbon::parse($fecha)->format('d/m/Y');

        return back()->with('ok', match ($resultado) {
            'fecha' => 'Fecha de baja registrada: último día el '.$dia.'. Los días siguientes no cuentan como previstos.',
            'cerrada' => 'Baja registrada con fecha '.$dia.'. El acceso de '.$user->name.' queda cerrado y su registro se conserva.'
                .($fecha === $hoy->toDateString() ? ' Hasta el final del día puedes anularla.' : ''),
            default => 'Baja prevista para el '.$dia.'. '.$user->name.' conserva el acceso hasta el final de ese día.',
        });
    }

    /**
     * Vuelta de una persona dada de baja. Los días entre la baja y la vuelta
     * quedan como interrupción: no cuentan como previstos. Mientras el último
     * día sea hoy o futuro, la baja solo se anula.
     */
    public function reincorporar(Request $request, User $user): RedirectResponse
    {
        if ((int) $request->user()->id === (int) $user->id) {
            return back()->with('aviso', 'No puedes cambiar tu propia baja.');
        }

        $hoy = now()->startOfDay();
        $mensajesMotivo = [
            'motivo_reincorporacion.required' => 'Explica el motivo.',
            'motivo_reincorporacion.min' => 'El motivo necesita al menos 3 caracteres.',
            'motivo_reincorporacion.max' => 'El motivo puede tener hasta 300 caracteres.',
        ];

        // Hasta el final del último día la baja se anula sin hueco, también la de
        // hoy que ya cerró el acceso: así un error al confirmarla tiene vuelta atrás.
        if ($user->bajaAnulableEl($hoy)) {
            $datos = $request->validate([
                'motivo_reincorporacion' => ['required', 'string', 'min:3', 'max:300'],
            ], $mensajesMotivo);

            try {
                $teniaAcceso = DB::transaction(function () use ($request, $user, $datos, $hoy): bool {
                    $persona = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                    if (! $persona->bajaAnulableEl($hoy)) {
                        throw new ReglaJornada('La baja de '.$persona->name.' ya no se puede anular.');
                    }
                    $antes = ['active' => (bool) $persona->active, 'baja_on' => $persona->baja_on?->toDateString()];
                    $persona->update(['active' => true, 'baja_on' => null]);
                    EventoCuenta::create([
                        'user_id' => $persona->id, 'actor_id' => $request->user()->id, 'tipo' => EventoCuenta::REINCORPORACION,
                        'detalles' => ['antes' => $antes, 'despues' => ['active' => true, 'baja_on' => null], 'anula_baja_programada' => true],
                        'motivo' => $datos['motivo_reincorporacion'], 'created_at' => now(),
                    ]);

                    return $antes['active'];
                });
            } catch (ReglaJornada $e) {
                return back()->with('aviso', $e->getMessage())->withInput();
            }

            return back()->with('ok', $teniaAcceso
                ? 'Baja prevista anulada. '.$user->name.' sigue en el equipo.'
                : 'Baja anulada. '.$user->name.' vuelve a tener acceso y sigue en el equipo.');
        }

        if (! $user->estaDeBajaEl($hoy)) {
            return back()->with('aviso', $user->name.' ya está en el equipo.');
        }

        $reglasFecha = ['required', 'date_format:Y-m-d', 'before_or_equal:'.$hoy->toDateString()];
        if ($user->baja_on) {
            $reglasFecha[] = 'after:'.$user->baja_on->toDateString();
        }
        if ($user->starts_on) {
            $reglasFecha[] = 'after_or_equal:'.$user->starts_on->toDateString();
        }
        $datos = $request->validate([
            'fecha_reincorporacion' => $reglasFecha,
            'motivo_reincorporacion' => ['required', 'string', 'min:3', 'max:300'],
        ], $mensajesMotivo + [
            'fecha_reincorporacion.required' => 'Elige el día en que vuelve.',
            'fecha_reincorporacion.date_format' => 'Elige una fecha de vuelta válida.',
            'fecha_reincorporacion.before_or_equal' => 'La vuelta no puede ser un día futuro: regístrala el día en que vuelve, porque el acceso se abre al guardar.',
            'fecha_reincorporacion.after' => 'La vuelta tiene que ser posterior al último día de la baja.',
            'fecha_reincorporacion.after_or_equal' => 'La vuelta no puede ser anterior al alta.',
        ]);
        $fecha = $datos['fecha_reincorporacion'];

        try {
            $persona = DB::transaction(function () use ($request, $user, $datos, $fecha, $hoy): User {
                $persona = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                if (! $persona->estaDeBajaEl($hoy)) {
                    throw new ReglaJornada($persona->name.' ya está en el equipo.');
                }

                $hueco = null;
                if ($persona->baja_on) {
                    $desde = $persona->baja_on->copy()->addDay()->toDateString();
                    $hasta = Carbon::parse($fecha)->subDay()->toDateString();
                    if ($desde <= $hasta) {
                        $ultimo = $this->ultimoDiaRegistrado($persona, $persona->baja_on->toDateString(), $hasta);
                        if ($ultimo) {
                            throw new ReglaJornada('Hay registro el '.$ultimo->format('d/m/Y').', durante la baja. Revísalo antes de reincorporar.');
                        }
                        $hueco = ['desde' => $desde, 'hasta' => $hasta];
                    }
                }

                $antes = ['active' => (bool) $persona->active, 'baja_on' => $persona->baja_on?->toDateString()];
                $evento = EventoCuenta::create([
                    'user_id' => $persona->id, 'actor_id' => $request->user()->id, 'tipo' => EventoCuenta::REINCORPORACION,
                    'detalles' => [
                        'antes' => $antes, 'despues' => ['active' => true, 'baja_on' => null],
                        'reincorporacion_on' => $fecha, 'interrupcion' => $hueco,
                    ],
                    'motivo' => $datos['motivo_reincorporacion'], 'created_at' => now(),
                ]);
                if ($hueco) {
                    InterrupcionPlantilla::create($hueco + ['user_id' => $persona->id, 'evento_cuenta_id' => $evento->id]);
                }
                $persona->update(['active' => true, 'baja_on' => null]);

                return $persona;
            });
        } catch (ReglaJornada $e) {
            return back()->with('aviso', $e->getMessage())->withInput();
        }

        $mensaje = $persona->name.' vuelve al equipo desde el '.Carbon::parse($fecha)->format('d/m/Y').' y puede entrar otra vez. Los días de baja no cuentan como previstos.';
        if ($fecha < $hoy->toDateString()) {
            $mensaje .= ' Los días laborables del '.Carbon::parse($fecha)->format('d/m/Y').' al '.$hoy->copy()->subDay()->format('d/m/Y').' cuentan como previstos aunque no pudo fichar en ellos.';
        }
        if ($persona->ends_on && $persona->ends_on->toDateString() < $fecha) {
            $mensaje .= ' Su fin de contrato era el '.$persona->ends_on->format('d/m/Y').': corrígelo en Editar alta.';
        }

        return redirect()->route('equipo.show', $persona)->with('ok', $mensaje);
    }

    /**
     * Último día con fichaje real u horas declaradas después de una fecha.
     * Una baja o un fin de contrato no pueden dejar fuera días ya registrados.
     */
    private function ultimoDiaRegistrado(User $persona, string $despuesDe, ?string $hasta = null): ?Carbon
    {
        $fichaje = Jornada::query()->where('user_id', $persona->id)
            ->whereDate('work_date', '>', $despuesDe)
            ->when($hasta, fn ($query) => $query->whereDate('work_date', '<=', $hasta))
            ->whereHas('tramos', fn ($query) => $query->where('tipo', Tramo::TRABAJO))
            ->max('work_date');
        $declarado = CargaAdministrativaDia::query()->where('user_id', $persona->id)->where('estado', 'vigente')
            ->whereDate('fecha', '>', $despuesDe)
            ->whereDate('fecha', '<', now()->toDateString())
            ->when($hasta, fn ($query) => $query->whereDate('fecha', '<=', $hasta))
            ->max('fecha');

        $dias = array_filter([$fichaje, $declarado]);

        return $dias === [] ? null : Carbon::parse(max(array_map(fn ($dia) => Carbon::parse($dia)->toDateString(), $dias)));
    }

    private function validarPersona(Request $request, bool $conClave): array
    {
        $reglas = [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'starts_on' => ['required', 'date'],
            'duracion' => ['required', Rule::enum(DuracionEstancia::class)],
            'ends_on' => ['nullable', 'required_if:duracion,'.DuracionEstancia::HastaElDia->value, 'date', 'after_or_equal:starts_on'],
            'tipo' => ['required', Rule::enum(TipoJornada::class)],
            'centro' => ['required', 'string', 'exists:centros,name'],
        ];
        if ($conClave) {
            $reglas['password'] = ['required', 'string', 'min:8'];
        }

        return $request->validate($reglas, [
            'name.required' => 'Escribe el nombre.',
            'email.required' => 'Escribe el correo.',
            'email.unique' => 'Ese correo ya está en uso.',
            'password.min' => 'La contraseña necesita al menos 8 caracteres.',
            'starts_on.required' => 'Elige desde qué día cuenta el registro.',
            'duracion.required' => 'Elige cuánto tiempo estará en la empresa.',
            'duracion.enum' => 'Elige cuánto tiempo estará en la empresa.',
            'ends_on.required_if' => 'Elige el último día en la empresa.',
            'ends_on.after_or_equal' => 'El último día no puede ser anterior al inicio.',
            'tipo.required' => 'Elige el tipo de jornada.',
            'tipo.enum' => 'Elige un tipo de jornada válido.',
            'centro.required' => 'Elige la sede municipal.',
            'centro.exists' => 'Elige una sede municipal válida.',
        ]);
    }

    private function validarHorario(Request $request): array
    {
        foreach (['morning_start', 'morning_end', 'afternoon_start', 'afternoon_end'] as $campo) {
            $valor = $request->input($campo);
            if (is_string($valor) && strlen($valor) >= 5) {
                $request->merge([$campo => substr($valor, 0, 5)]);
            }
        }

        return $request->validate([
            'morning_start' => ['required', 'date_format:H:i'],
            'morning_end' => ['required', 'date_format:H:i'],
            'afternoon_start' => ['required', 'date_format:H:i'],
            'afternoon_end' => ['required', 'date_format:H:i'],
        ], [
            'morning_start.required' => 'Elige la hora de inicio.',
            'morning_end.required' => 'Elige el fin de la mañana.',
            'afternoon_start.required' => 'Elige el inicio de la tarde.',
            'afternoon_end.required' => 'Elige el fin de la jornada.',
            '*.date_format' => 'Elige una hora.',
        ]);
    }

    private function guardarHorario(User $persona, array $datos, string $desde, ?TipoJornada $tipo): void
    {
        $tardeIgual = $datos['afternoon_start'] === $datos['afternoon_end'];
        $tardeValida = $tardeIgual
            ? $datos['morning_end'] === $datos['afternoon_start']
            : $datos['afternoon_start'] < $datos['afternoon_end'];

        if (! ($datos['morning_start'] < $datos['morning_end']
            && $datos['morning_end'] <= $datos['afternoon_start']
            && $tardeValida)) {
            throw new ReglaJornada('Revisa el horario: la mañana va antes que la tarde, y cada tramo termina después de empezar.');
        }

        Horario::create([
            'user_id' => $persona->id,
            'tipo' => $tipo?->value,
            'morning_start' => $datos['morning_start'],
            'morning_end' => $datos['morning_end'],
            'afternoon_start' => $datos['afternoon_start'],
            'afternoon_end' => $datos['afternoon_end'],
            'effective_from' => $desde,
        ]);
    }

    private function fechaConsulta(Request $request, string $campo): ?string
    {
        $valor = $request->old($campo, $request->query($campo));
        if (! is_string($valor) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) !== 1
            || ! checkdate((int) substr($valor, 5, 2), (int) substr($valor, 8, 2), (int) substr($valor, 0, 4))) {
            return null;
        }

        return $valor;
    }
}
