<?php

namespace App\Http\Controllers;

use App\Exceptions\ReglaJornada;
use App\Models\Aviso;
use App\Models\Consulta;
use App\Models\Correccion;
use App\Models\Explicacion;
use App\Models\Horario;
use App\Models\Jornada;
use App\Models\Tramo;
use App\Models\User;
use App\Services\JornadaService;
use App\Services\RegistroCsv;
use App\Support\Tiempo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RegistroController extends Controller
{
    public function __construct(private JornadaService $jornada, private RegistroCsv $csv) {}

    public function index(Request $request): View
    {
        return $this->mostrar($request, $request->user(), $request->user(), false);
    }

    public function de(Request $request, User $user): View
    {
        if ($motivo = $this->pedirMotivo($request, $user)) {
            return $motivo;
        }

        return $this->mostrar($request, $request->user(), $user, true);
    }

    public function motivo(Request $request, User $user): RedirectResponse
    {
        $this->autorizar($request->user(), $user);

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

        return redirect()->intended(route('equipo.registro', $user))
            ->with('ok', 'Guardado. Esta consulta queda escrita.');
    }

    public function show(Request $request, Jornada $jornada): View
    {
        $jornada->load('user', 'tramos.correcciones.autor', 'tramos.cerradoPor');
        $this->autorizar($request->user(), $jornada->user);

        if ($motivo = $this->pedirMotivo($request, $jornada->user)) {
            return $motivo;
        }

        $persona = $jornada->user;
        $detalle = $this->detalle($jornada, $persona);
        $puedeCorregir = $jornada->work_date->gte(now()->subDays(7)->startOfDay());
        $puedeRegistrar = $detalle['filas'] === [] && ($request->user()->esJefa() || $puedeCorregir);
        $explicar = $this->explicarDe($persona, $jornada->work_date->toDateString());

        return view('registro.show', [
            'jornada' => $jornada,
            'persona' => $persona,
            'detalle' => $detalle,
            'puedeCorregir' => $puedeCorregir,
            'motivos' => Correccion::MOTIVOS,
            'puedeFicharPausa' => $this->puedeFicharPausa($detalle),
            'esJefa' => $request->user()->esJefa(),
            'explicar' => $explicar,
            'puedeRegistrar' => $puedeRegistrar,
            'crumb' => $this->volverAlRegistro($request->user(), $persona, $jornada->work_date->toDateString()),
            ...$this->revisionDelDia($request, $puedeCorregir, $puedeRegistrar, $explicar),
        ]);
    }

    public function dia(Request $request, string $fecha): View|RedirectResponse
    {
        $dia = $this->fechaDeRegistro($fecha);

        $persona = $request->user();
        $explicar = $this->explicarDe($persona, $fecha);
        abort_unless($explicar, 404);

        $jornada = Jornada::query()
            ->where('user_id', $persona->id)
            ->whereDate('work_date', $fecha)
            ->first();
        if ($jornada) {
            return redirect()->route('registro.show', array_filter([
                'jornada' => $jornada,
                'abrir' => $request->query('abrir'),
            ]));
        }

        $puedeRegistrar = $dia->gte(now()->subDays(7)->startOfDay()) || $persona->esJefa();

        return view('registro.show', [
            'jornada' => null,
            'persona' => $persona,
            'detalle' => $this->detalleSinFichaje($persona, $dia, $explicar['texto']),
            'puedeCorregir' => false,
            'motivos' => Correccion::MOTIVOS,
            'esJefa' => $persona->esJefa(),
            'explicar' => $explicar,
            'puedeRegistrar' => $puedeRegistrar,
            'crumb' => $this->volverAlRegistro($persona, $persona, $fecha),
            ...$this->revisionDelDia($request, false, $puedeRegistrar, $explicar),
        ]);
    }

    public function registrarHoras(Request $request, string $fecha): RedirectResponse
    {
        $dia = $this->fechaDeRegistro($fecha);

        foreach (['inicio', 'fin'] as $campo) {
            $valor = $request->input($campo);
            if (is_string($valor) && strlen($valor) >= 5) {
                $request->merge([$campo => substr($valor, 0, 5)]);
            }
        }

        $datos = $request->validate([
            'inicio' => ['required', 'date_format:H:i'],
            'fin' => ['required', 'date_format:H:i'],
        ], [
            'inicio.required' => 'Elige la hora de inicio.',
            'fin.required' => 'Elige la hora de fin.',
            'inicio.date_format' => 'Elige una hora.',
            'fin.date_format' => 'Elige una hora.',
        ]);

        try {
            $jornada = $this->jornada->registrarHoras($request->user(), $fecha, $datos['inicio'], $datos['fin']);
        } catch (ReglaJornada $e) {
            return back()->with('aviso', $e->getMessage());
        }

        return redirect()->route('registro.show', $jornada)->with('ok', 'Guardado. Horas registradas de las '.$datos['inicio'].' a las '.$datos['fin'].'.');
    }

    /**
     * @param  array{fecha: string, dia: string, texto: string}|null  $explicar
     * @return array{abrirRegistrar: bool, abrirCorregir: bool, puedeExplicar: bool, abrirExplicar: bool}
     */
    private function revisionDelDia(Request $request, bool $puedeCorregir, bool $puedeRegistrar, ?array $explicar): array
    {
        $abrir = $request->query('abrir');
        $puedeExplicar = $explicar !== null && ! $puedeCorregir && ! $puedeRegistrar;

        return [
            'abrirRegistrar' => $puedeRegistrar && $abrir === 'registrar-horas',
            'abrirCorregir' => $puedeCorregir && $abrir === 'corregir',
            'puedeExplicar' => $puedeExplicar,
            'abrirExplicar' => $puedeExplicar && in_array($abrir, ['registrar-horas', 'corregir', 'explicar'], true),
        ];
    }

    /**
     * @return array{fecha: string, dia: string, texto: string}|null
     */
    private function explicarDe(User $persona, string $fecha): ?array
    {
        foreach ($this->jornada->pendientesDeExplicar($persona, now()) as $pendiente) {
            if ($pendiente['fecha'] === $fecha) {
                return $pendiente;
            }
        }

        $avisoHistorico = Aviso::query()->where('user_id', $persona->id)
            ->whereDate('work_date', $fecha)->where('tipo', 'inicio')
            ->where('cuenta', true)->whereNull('responded_at')->exists();
        if ($avisoHistorico) {
            return [
                'fecha' => $fecha,
                'dia' => $this->fechaDeRegistro($fecha)->locale('es')->isoFormat('dddd D [de] MMMM'),
                'texto' => 'Este día quedó sin fichaje. La explicación se conserva en el registro.',
            ];
        }

        return null;
    }

    private function fechaDeRegistro(string $fecha): Carbon
    {
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) === 1
            && checkdate((int) substr($fecha, 5, 2), (int) substr($fecha, 8, 2), (int) substr($fecha, 0, 4)), 404);

        return Carbon::createFromFormat('!Y-m-d', $fecha);
    }

    private function puedeFicharPausa(array $detalle): bool
    {
        return collect($detalle['filas'])->contains(fn (array $fila) => $fila['puedeFicharPausa'] ?? false);
    }

    public function corregir(Request $request, Tramo $tramo): RedirectResponse
    {
        if ($request->input('motivo') === 'fichar_pausa') {
            $this->normalizarHora($request, 'inicio');
            $this->normalizarHora($request, 'fin');
            $datos = $request->validate([
                'motivo' => ['required', 'in:fichar_pausa'],
                'inicio' => ['required', 'date_format:H:i'],
                'fin' => ['required', 'date_format:H:i'],
                'nota' => ['nullable', 'string', 'max:500'],
            ], [
                'inicio.required' => 'Elige la hora de inicio de la pausa.',
                'inicio.date_format' => 'Elige una hora de inicio válida.',
                'fin.required' => 'Elige la hora de fin de la pausa.',
                'fin.date_format' => 'Elige una hora de fin válida.',
                'nota.max' => 'La nota puede tener hasta 500 caracteres.',
            ]);

            try {
                $mensaje = $this->jornada->ficharPausa(
                    $request->user(),
                    $tramo,
                    $datos['inicio'],
                    $datos['fin'],
                    $datos['nota'] ?? null,
                );
            } catch (ReglaJornada $e) {
                return back()->with('aviso', $e->getMessage());
            }

            return back()->with('ok', $mensaje);
        }

        if (! $request->filled('campo')) {
            return $this->guardarHoras($request, $tramo);
        }

        $hora = $request->input('hora');
        if (is_string($hora) && strlen($hora) >= 5) {
            $request->merge(['hora' => substr($hora, 0, 5)]);
        }

        $datos = $request->validate([
            'campo' => ['required', 'in:started_at,ended_at'],
            'hora' => ['required', 'date_format:H:i'],
            'motivo' => ['required', 'in:'.implode(',', array_keys(Correccion::MOTIVOS))],
            'nota' => ['nullable', 'string', 'max:500'],
        ], [
            'hora.required' => 'Elige una hora.',
            'hora.date_format' => 'Elige una hora.',
            'motivo.required' => 'Elige un motivo.',
            'nota.max' => 'La nota puede tener hasta 500 caracteres.',
        ]);

        try {
            $mensaje = $this->jornada->corregir(
                $request->user(),
                $tramo,
                $datos['campo'],
                $datos['hora'],
                $datos['motivo'],
                $datos['nota'] ?? null,
            );
        } catch (ReglaJornada $e) {
            return back()->with('aviso', $e->getMessage());
        }

        return back()->with('ok', $mensaje);
    }

    private function normalizarHora(Request $request, string $campo): void
    {
        $hora = $request->input($campo);
        if (is_string($hora) && strlen($hora) >= 5) {
            $request->merge([$campo => substr($hora, 0, 5)]);
        }
    }

    private function guardarHoras(Request $request, Tramo $tramo): RedirectResponse
    {
        foreach (['inicio', 'fin'] as $campo) {
            $valor = $request->input($campo);
            if (is_string($valor) && strlen($valor) >= 5) {
                $request->merge([$campo => substr($valor, 0, 5)]);
            }
        }

        $datos = $request->validate([
            'inicio' => ['nullable', 'date_format:H:i'],
            'fin' => ['nullable', 'date_format:H:i'],
            'motivo' => ['required', 'in:'.implode(',', array_keys(Correccion::MOTIVOS))],
            'nota' => ['nullable', 'string', 'max:500'],
        ], [
            'inicio.date_format' => 'Elige una hora de inicio.',
            'fin.date_format' => 'Elige una hora de fin.',
            'motivo.required' => 'Elige un motivo.',
            'nota.max' => 'La nota puede tener hasta 500 caracteres.',
        ]);

        try {
            $mensaje = $this->jornada->guardarHoras(
                $request->user(),
                $tramo,
                $datos['inicio'] ?? null,
                $datos['fin'] ?? null,
                $datos['motivo'],
                $datos['nota'] ?? null,
            );
        } catch (ReglaJornada $e) {
            return back()->with('aviso', $e->getMessage());
        }

        return back()->with('ok', $mensaje);
    }

    public function explicar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'body' => ['required', 'string', 'max:500'],
            'work_date' => ['required', 'date'],
        ], [
            'body.required' => 'Escribe la explicación.',
            'body.max' => 'La nota puede tener hasta 500 caracteres.',
            'work_date.required' => 'Elige el día.',
        ]);

        Explicacion::create([
            'user_id' => $request->user()->id,
            'body' => trim($datos['body']),
            'work_date' => $datos['work_date'],
        ]);

        $this->jornada->marcarExplicados($request->user(), $datos['work_date']);

        return back()->with('ok', 'Guardado. Tu explicación queda en el registro.');
    }

    public function csv(Request $request): StreamedResponse
    {
        return $this->descargar($request, $request->user(), $request->user());
    }

    public function csvDe(Request $request, User $user): StreamedResponse|View
    {
        if ($motivo = $this->pedirMotivo($request, $user)) {
            return $motivo;
        }

        return $this->descargar($request, $request->user(), $user);
    }

    /**
     * @return array<string, mixed>
     */
    private function detalle(Jornada $jornada, User $persona): array
    {
        $horario = $persona->horarioEn($jornada->work_date);
        $fecha = $jornada->work_date->copy()->locale('es');
        $trabajos = $jornada->tramos->where('tipo', Tramo::TRABAJO)->values();
        $abierta = $jornada->tramoAbierto() !== null;
        $primero = $trabajos->first();
        $ultimo = $trabajos->filter(fn (Tramo $tramo) => $tramo->ended_at !== null)->last();
        $filas = [];
        $barras = [];
        $pausas = $jornada->tramos
            ->where('tipo', Tramo::PAUSA)
            ->filter(fn (Tramo $pausa) => $pausa->ended_at !== null)
            ->sortBy('started_at');

        foreach ($jornada->tramos as $tramo) {
            $fin = $this->finVisible($tramo, $jornada, $horario);
            $minutos = (int) round($tramo->started_at->diffInMinutes($fin));
            $esPrimerTrabajo = $tramo->tipo === Tramo::TRABAJO && $tramo === $trabajos->first();
            $filas[] = [
                'tramo' => $tramo,
                'titulo' => $this->tituloTramo($tramo, $horario),
                'horas' => Tiempo::hora($tramo->started_at).'–'.$this->textoFin($tramo, $jornada),
                'duracion' => Tiempo::corto($minutos).' min',
                'nota' => $this->notaTramo($tramo),
                'visible' => $tramo->tipo !== Tramo::TRABAJO || $esPrimerTrabajo,
                'puedeFicharPausa' => $esPrimerTrabajo
                    && ! $jornada->tramos->contains('tipo', Tramo::PAUSA)
                    && $tramo->ended_at !== null,
            ];
            if ($tramo->tipo === Tramo::TRABAJO) {
                foreach ($this->segmentosSinPausas($tramo->started_at, $fin, $pausas) as [$inicio, $segmentoFin]) {
                    $barra = $this->ejeDetalle($inicio, $segmentoFin);
                    if ($barra) {
                        $barras[] = $barra;
                    }
                }
            }
        }

        $cambios = [];
        foreach ($jornada->tramos as $tramo) {
            foreach ($tramo->correcciones as $correccion) {
                $campo = $correccion->field === 'ended_at' ? 'Salida' : 'Inicio';
                $cambios[] = [
                    'cuando' => $correccion->created_at,
                    'titulo' => $campo.': '.Tiempo::hora($correccion->previous_value).' → '.Tiempo::hora($correccion->new_value),
                    'texto' => 'Antes '.Tiempo::hora($correccion->previous_value).', ahora '.Tiempo::hora($correccion->new_value).'. '.$correccion->motivoTexto().'.'.($correccion->note ? ' '.$correccion->note : ''),
                    'quien' => ($correccion->autor->name ?? 'Alguien').' · '.$this->cuandoCorto($correccion->created_at),
                ];
            }
            if ($tramo->cerrado_at && $tramo->cerradoPor) {
                $cambios[] = [
                    'cuando' => $tramo->cerrado_at,
                    'titulo' => 'Salida declarada por '.$tramo->cerradoPor->name.' a las '.Tiempo::hora($tramo->cerrado_at).'.',
                    'texto' => '',
                    'quien' => $tramo->cerradoPor->name.' · '.$this->cuandoCorto($tramo->cerrado_at),
                ];
            }
        }
        $cambios = array_merge($cambios, $this->notasDelDia($persona, $jornada->work_date->toDateString()));
        usort($cambios, fn (array $a, array $b) => $a['cuando'] <=> $b['cuando']);

        $cantidad = $trabajos->count();
        $retraso = $this->jornada->diasDeRetraso($jornada);
        $estadoRetraso = $retraso > 0 ? 'Fichado fuera de plazo' : 'Jornada cerrada';

        return [
            'titulo' => ucfirst($fecha->isoFormat('dddd')).', '.$fecha->isoFormat('D [de] MMMM'),
            'total' => Tiempo::corto($this->jornada->minutosRegistrados($persona, $jornada)),
            'porEncima' => $this->jornada->porEncimaRegistrado($persona, $jornada),
            'tramosTexto' => match ($cantidad) {
                0 => 'Sin tramos de trabajo',
                1 => 'Un tramo de trabajo',
                2 => 'Dos tramos de trabajo',
                default => $cantidad.' tramos de trabajo',
            },
            'inicio' => $primero ? Tiempo::hora($primero->started_at) : '—',
            'salida' => $ultimo ? Tiempo::hora($ultimo->ended_at) : ($abierta && ! $jornada->work_date->isSameDay(now()) ? 'sin salida' : '—'),
            'salidaTexto' => $abierta
                ? ($jornada->work_date->isSameDay(now()) ? 'Jornada abierta' : 'Sin salida')
                : ($jornada->closed_late ? 'Cerrada al día siguiente' : $estadoRetraso),
            'estado' => $abierta ? 'Abierta' : ($jornada->closed_late ? 'Cierre al día siguiente' : $estadoRetraso),
            'estadoClase' => $abierta ? 'abierta' : (($jornada->closed_late || $retraso > 0) ? 'tardia' : 'cerrada'),
            'previsto' => $horario ? str_replace(' y ', ' · ', $horario->texto()) : null,
            'fecha' => $jornada->work_date->toDateString(),
            'filas' => $filas,
            'barras' => $barras,
            'cambios' => $cambios,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detalleSinFichaje(User $persona, Carbon $dia, string $aviso): array
    {
        $horario = $persona->horarioEn($dia);
        $fecha = $dia->copy()->locale('es');

        return [
            'titulo' => ucfirst($fecha->isoFormat('dddd')).', '.$fecha->isoFormat('D [de] MMMM'),
            'total' => '0 h 00',
            'porEncima' => null,
            'tramosTexto' => 'Sin tramos de trabajo',
            'inicio' => '—',
            'salida' => '—',
            'salidaTexto' => 'Sin fichaje',
            'estado' => 'Sin fichaje',
            'estadoClase' => 'tardia',
            'previsto' => $horario ? str_replace(' y ', ' · ', $horario->texto()) : null,
            'fecha' => $dia->toDateString(),
            'filas' => [],
            'barras' => [],
            'cambios' => $this->notasDelDia($persona, $dia->toDateString()),
            'aviso' => $aviso,
        ];
    }

    private function tituloTramo(Tramo $tramo, ?Horario $horario): string
    {
        if ($tramo->tipo === Tramo::PAUSA) {
            return 'Pausa';
        }
        if ($tramo->fuera_del_equipo) {
            return 'Sin el portátil';
        }
        if ($tramo->situacion === 'incidencia') {
            return 'Incidencia de conexión';
        }

        return 'Tramo del día';
    }

    private function horarioCompleto(Horario $horario): string
    {
        $inicio = $horario->corta($horario->morning_start);
        $fin = $horario->sinTarde()
            ? $horario->corta($horario->morning_end)
            : $horario->corta($horario->afternoon_end);

        return $inicio.'–'.$fin;
    }

    private function notaTramo(Tramo $tramo): string
    {
        $partes = [];
        if ($tramo->situacion === 'incidencia') {
            $partes[] = 'Declarada a las '.Tiempo::hora($tramo->anotado_at);
            if ($tramo->nota) {
                $partes[] = $tramo->nota;
            }
        } elseif ($tramo->anotado_at && $tramo->anotado_at->format('H:i') !== $tramo->started_at->format('H:i')) {
            $partes[] = 'Anotado a las '.Tiempo::hora($tramo->anotado_at);
        }

        return implode('. ', $partes);
    }

    private function textoFin(Tramo $tramo, Jornada $jornada): string
    {
        if ($tramo->ended_at) {
            return Tiempo::hora($tramo->ended_at);
        }

        if (! $jornada->work_date->isSameDay(now())) {
            return 'sin salida';
        }

        return 'ahora';
    }

    private function finVisible(Tramo $tramo, Jornada $jornada, ?Horario $horario): Carbon
    {
        if ($tramo->ended_at) {
            return $tramo->ended_at;
        }

        $ahora = now();
        if (! $jornada->work_date->isSameDay($ahora)) {
            return $tramo->started_at;
        }
        if (! $horario || $jornada->sigue || $tramo->tipo !== Tramo::TRABAJO) {
            return $ahora;
        }

        $tope = Carbon::parse($jornada->work_date->toDateString().' '.$horario->corta($horario->afternoon_end), config('app.timezone'));
        if ($tramo->started_at->lt($tope) && $ahora->gt($tope)) {
            return $tope;
        }

        return $ahora;
    }

    /**
     * @return array{left: float, width: float}|null
     */
    private function ejeDetalle(Carbon $inicio, Carbon $fin): ?array
    {
        $desde = 7 * 60;
        $hasta = 21 * 60;
        $a = max($desde, min($hasta, $this->minutoDetalle($inicio)));
        $b = max($desde, min($hasta, $this->minutoDetalle($fin)));
        if ($b <= $a) {
            return null;
        }

        return [
            'left' => round(($a - $desde) / ($hasta - $desde) * 100, 2),
            'width' => round(($b - $a) / ($hasta - $desde) * 100, 2),
        ];
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

    private function minutoDetalle(Carbon $momento): int
    {
        $local = $momento->timezone(config('app.timezone'));

        return ((int) $local->format('H')) * 60 + (int) $local->format('i');
    }

    private function cuandoCorto(Carbon $momento): string
    {
        $local = $momento->timezone(config('app.timezone'))->locale('es');

        return $local->day.' '.rtrim($local->isoFormat('MMM'), '.').', '.$local->format('H:i');
    }

    private function mostrar(Request $request, User $visor, User $persona, bool $consulta): View
    {
        $this->autorizar($visor, $persona);

        $filtros = $this->filtros($request);
        $periodo = $this->periodo($request, $filtros);
        $periodo = $this->semanaEnCursoSiNoHayFiltro($request, $filtros, $periodo);
        $buscado = $filtros['desde'] !== '' || $filtros['hasta'] !== '' || $filtros['estado'] !== '' || $filtros['incidencia'] !== '';
        $registros = $this->csv->combinadas($persona, $periodo['desde'], $periodo['hasta'], $filtros['estado'], $filtros['incidencia']);
        $pagina = LengthAwarePaginator::resolveCurrentPage();
        $jornadas = new LengthAwarePaginator($registros->forPage($pagina, 60)->values(), $registros->count(), 60, $pagina, [
            'path' => $request->url(), 'query' => $request->query(),
        ]);

        return view('registro.index', [
            'persona' => $persona,
            'consulta' => $consulta,
            'jornadas' => $jornadas,
            'buscado' => $buscado,
            'periodo' => $periodo['clave'],
            'desde' => $periodo['desde'],
            'hasta' => $periodo['hasta'],
            'rango' => $this->rango($periodo['desde'], $periodo['hasta']),
            'estado' => $filtros['estado'],
            'incidencia' => $filtros['incidencia'],
            'tabla' => $this->csv,
            'explicaciones' => $persona->explicaciones()->latest()->limit(10)->get(),
            'consultas' => $consulta
                ? Consulta::query()->with('viewer')->where('subject_id', $persona->id)->latest()->limit(10)->get()
                : collect(),
            'esJefa' => $visor->esJefa(),
            'propio' => $visor->id === $persona->id,
        ]);
    }

    private function descargar(Request $request, User $visor, User $persona): StreamedResponse
    {
        $this->autorizar($visor, $persona);

        $filtros = $this->filtros($request);
        $periodo = $this->periodo($request, $filtros);

        return $this->csv->descargar($persona, $periodo['desde'], $periodo['hasta'], $filtros['estado'], $filtros['incidencia']);
    }

    /**
     * @return array{desde: string, hasta: string, estado: string, incidencia: string}
     */
    private function filtros(Request $request): array
    {
        $desde = $this->fechaFiltro($request->query('desde'));
        $hasta = $this->fechaFiltro($request->query('hasta'));
        $estado = $request->query('estado');
        $incidencia = $request->query('incidencia');
        if (($request->filled('desde') && $desde === '') || ($request->filled('hasta') && $hasta === '')) {
            $request->session()->now('aviso', 'Se ha omitido una fecha no válida del filtro. Usa el formato día, mes y año del selector.');
        }
        if ($desde !== '' && $hasta !== '' && $desde > $hasta) {
            $desde = '';
            $hasta = '';
            $request->session()->now('aviso', 'La fecha de inicio debe ser anterior o igual a la fecha de fin. Se ha quitado el rango incorrecto.');
        }

        return [
            'desde' => $desde,
            'hasta' => $hasta,
            'estado' => in_array($estado, ['cerrada', 'pendiente', 'tardia', 'fuera_plazo', 'carga', 'prevision'], true) ? $estado : '',
            'incidencia' => in_array($incidencia, ['si', 'no'], true) ? $incidencia : '',
        ];
    }

    private function fechaFiltro(mixed $fecha): string
    {
        return is_string($fecha) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $fecha, $partes)
            && checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1]) ? $fecha : '';
    }

    /**
     * @param  array{desde: string, hasta: string, estado: string, incidencia: string}  $filtros
     * @return array{clave: string, desde: string, hasta: string}
     */
    private function periodo(Request $request, array $filtros): array
    {
        $pedido = $request->query('periodo');
        $pedido = is_string($pedido) ? $pedido : '';
        $hayFiltro = $filtros['desde'] !== '' || $filtros['hasta'] !== '' || $filtros['estado'] !== '' || $filtros['incidencia'] !== '';
        if (! in_array($pedido, ['semana', 'mes', 'anterior', 'todo'], true)) {
            $pedido = $hayFiltro ? '' : 'semana';
        }

        $desde = $filtros['desde'];
        $hasta = $filtros['hasta'];
        if ($pedido === 'semana') {
            $desde = now()->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
            $hasta = now()->copy()->endOfWeek(Carbon::SUNDAY)->toDateString();
        } elseif ($pedido === 'mes') {
            $desde = now()->copy()->startOfMonth()->toDateString();
            $hasta = now()->copy()->endOfMonth()->toDateString();
        } elseif ($pedido === 'anterior') {
            $mes = now()->copy()->subMonthNoOverflow();
            $desde = $mes->copy()->startOfMonth()->toDateString();
            $hasta = $mes->copy()->endOfMonth()->toDateString();
        }

        return ['clave' => $pedido, 'desde' => $desde, 'hasta' => $hasta];
    }

    /**
     * Sin filtros, Mi registro abre la semana en curso.
     * La descarga sigue usando periodo() y no entra por aquí.
     *
     * @param  array{desde: string, hasta: string, estado: string, incidencia: string}  $filtros
     * @param  array{clave: string, desde: string, hasta: string}  $periodo
     * @return array{clave: string, desde: string, hasta: string}
     */
    private function semanaEnCursoSiNoHayFiltro(Request $request, array $filtros, array $periodo): array
    {
        $pedido = $request->query('periodo');
        $pedido = is_string($pedido) ? $pedido : '';
        $sinAcotar = $filtros['desde'] === ''
            && $filtros['hasta'] === ''
            && $filtros['estado'] === ''
            && $filtros['incidencia'] === ''
            && ! in_array($pedido, ['semana', 'mes', 'anterior', 'todo'], true);

        if (! $sinAcotar) {
            return $periodo;
        }

        return [
            'clave' => 'semana',
            'desde' => now()->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
            'hasta' => now()->copy()->endOfWeek(Carbon::SUNDAY)->toDateString(),
        ];
    }

    private function rango(string $desde, string $hasta): string
    {
        if ($desde === '' || $hasta === '') {
            return 'Todos los días';
        }

        $inicio = Carbon::parse($desde)->locale('es');
        $fin = Carbon::parse($hasta)->locale('es');
        if ($inicio->isSameDay($fin)) {
            return $inicio->day.' de '.$inicio->isoFormat('MMMM');
        }
        if ($inicio->month === $fin->month && $inicio->year === $fin->year) {
            return $inicio->day.'–'.$fin->day.' de '.$fin->isoFormat('MMMM');
        }

        return $inicio->day.' de '.$inicio->isoFormat('MMMM').' – '.$fin->day.' de '.$fin->isoFormat('MMMM');
    }

    /**
     * @return list<array{cuando: Carbon, titulo: string, texto: string, quien: string}>
     */
    private function notasDelDia(User $persona, string $fecha): array
    {
        return $persona->explicaciones()
            ->whereDate('work_date', $fecha)
            ->get()
            ->map(fn (Explicacion $explicacion) => [
                'cuando' => $explicacion->created_at,
                'titulo' => 'Nota',
                'texto' => $explicacion->body,
                'quien' => $persona->name.' · '.$this->cuandoCorto($explicacion->created_at),
            ])
            ->all();
    }

    /**
     * @return array{texto: string, url: string}
     */
    private function volverAlRegistro(User $visor, User $persona, string $fecha): array
    {
        $dia = Carbon::parse($fecha);
        $ancla = '#dia-'.$fecha;
        if ($visor->id !== $persona->id) {
            return [
                'texto' => 'Registro de '.$persona->name,
                'url' => route('equipo.registro', $persona).$ancla,
            ];
        }

        $hoy = now();
        if ($dia->between($hoy->copy()->startOfWeek(Carbon::MONDAY)->startOfDay(), $hoy->copy()->endOfWeek(Carbon::SUNDAY)->endOfDay())) {
            $params = ['periodo' => 'semana'];
        } elseif ($dia->isSameMonth($hoy)) {
            $params = ['periodo' => 'mes'];
        } elseif ($dia->isSameMonth($hoy->copy()->subMonthNoOverflow())) {
            $params = ['periodo' => 'anterior'];
        } else {
            $params = [
                'periodo' => 'todo',
                'desde' => $dia->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
                'hasta' => $dia->copy()->endOfWeek(Carbon::SUNDAY)->toDateString(),
            ];
        }

        return [
            'texto' => 'Mi registro',
            'url' => route('registro', $params).$ancla,
        ];
    }

    private function pedirMotivo(Request $request, User $persona): ?View
    {
        if ($request->user()->id === $persona->id || $request->session()->get('consulta.'.$persona->id)) {
            return null;
        }

        $request->session()->put('url.intended', url()->full());

        return view('registro.motivo', [
            'persona' => $persona,
            'esJefa' => true,
        ]);
    }

    private function autorizar(User $visor, User $persona): void
    {
        if ($visor->id !== $persona->id && ! $visor->esJefa()) {
            abort(403);
        }
    }
}
