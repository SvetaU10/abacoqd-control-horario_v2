<?php

namespace App\Http\Controllers;

use App\Exceptions\ReglaJornada;
use App\FestivosCsvService;
use App\Models\Ausencia;
use App\Models\Centro;
use App\Models\FalloComun;
use App\Models\Festivo;
use App\Models\Jornada;
use App\Models\SolicitudVacaciones;
use App\Models\User;
use App\Services\CupoVacaciones;
use App\Services\JornadaService;
use App\Services\NagerFestivos;
use App\Support\DiaFestivo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CalendarioController extends Controller
{
    public function __construct(
        private JornadaService $jornada,
        private NagerFestivos $festivos,
        private CupoVacaciones $cupo,
    ) {}

    public function index(Request $request): View
    {
        $anio = (int) $request->query('anio', now()->year);
        if ($anio < 2020 || $anio > now()->year + 5) {
            $anio = now()->year;
        }

        $mes = $request->query('mes') === null
            ? ($anio === now()->year ? now()->month : 1)
            : (int) $request->query('mes');
        if ($mes < 1 || $mes > 12) {
            $mes = $anio === now()->year ? now()->month : 1;
        }

        $cursor = Carbon::create($anio, $mes, 1)->startOfMonth();
        $anterior = $cursor->copy()->subMonth();
        $siguiente = $cursor->copy()->addMonth();
        $mesAnterior = $anterior->greaterThanOrEqualTo(Carbon::create(2020, 1, 1))
            ? ['anio' => $anterior->year, 'mes' => $anterior->month]
            : null;
        $mesSiguiente = $siguiente->lessThanOrEqualTo(Carbon::create(now()->year + 5, 12, 1))
            ? ['anio' => $siguiente->year, 'mes' => $siguiente->month]
            : null;

        $usuario = $request->user();
        $centro = $usuario->sedeEn(now()) ?? Centro::query()->orderBy('name')->firstOrFail();
        $festivos = $this->festivos->dePersona($usuario, $anio);
        $propias = $usuario->ausencias()->whereYear('absence_date', $anio)->get()
            ->keyBy(fn (Ausencia $ausencia) => $ausencia->absence_date->toDateString());
        $jornadas = Jornada::query()
            ->where('user_id', $usuario->id)
            ->whereYear('work_date', $anio)
            ->get()
            ->keyBy(fn (Jornada $jornada) => $jornada->work_date->toDateString());
        $pendientes = $usuario->solicitudesVacaciones()
            ->where('estado', SolicitudVacaciones::PENDIENTE)
            ->get()
            ->flatMap(fn (SolicitudVacaciones $solicitud) => $this->cupo->queCuentan($usuario, $solicitud->starts_on, $solicitud->ends_on))
            ->flip();
        $meses = $this->meses($anio, $festivos, $propias, $jornadas, $pendientes);
        $mesActual = $meses[$mes - 1];
        $vistaAnio = $request->query('vista') === 'anio';
        $datos = [
            'festivos' => $festivos,
            'mes' => $mesActual,
            'meses' => $meses,
            'vistaAnio' => $vistaAnio,
            'eventos' => $this->eventosDelMes($mesActual),
            'anio' => $anio,
            'mesAnterior' => $mesAnterior,
            'mesSiguiente' => $mesSiguiente,
            'anioAnterior' => $anio > 2020 ? ['anio' => $anio - 1, 'mes' => $mes] : null,
            'anioSiguiente' => $anio < now()->year + 5 ? ['anio' => $anio + 1, 'mes' => $mes] : null,
            'centro' => $centro,
            'tramos' => $this->festivos->vigenciasDePersona($usuario, $anio),
            'sinCalendario' => ! $this->festivos->coberturaPersonaConfirmada($usuario, $anio),
            'fallos' => FalloComun::query()->orderByDesc('falla_date')->limit(12)->get(),
            // Panel del año que se está viendo, no del año en curso.
            'vacaciones' => $this->cupo->resumen($usuario, $anio),
            'vacacionesEnPlazo' => $this->cupo->dentroDelPlazo(Carbon::create($anio, 1, 1))
                && Carbon::create($anio, 12, 31)->gte(now()->startOfDay()),
        ];

        if ($request->query('fragmento') === '1') {
            return view('calendario.contenido', $datos);
        }

        return view('calendario.index', $datos);
    }

    public function plantillaFestivos(): StreamedResponse
    {
        $ruta = database_path('seeders/data/festivos-madrid-valencia-2027.csv');

        return response()->streamDownload(static function () use ($ruta): void {
            echo (string) file_get_contents($ruta);
        }, 'plantilla-festivos.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function previsualizarFestivos(Request $request, FestivosCsvService $csv): RedirectResponse
    {
        $datos = $request->validate([
            'archivo' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ], [
            'archivo.required' => 'Selecciona un CSV.',
            'archivo.mimes' => 'El fichero debe ser CSV.',
            'archivo.max' => 'El CSV no puede superar 2 MB.',
        ]);

        try {
            $resultado = $csv->leer($datos['archivo']);
        } catch (\RuntimeException $error) {
            return back()->with('aviso', $error->getMessage());
        }

        if ($resultado['filas'] === []) {
            $request->session()->put('festivos_preview', []);
            $request->session()->put('festivos_errores', $resultado['errores']);

            return back()->with('aviso', 'El CSV no contiene filas válidas para importar.');
        }

        $request->session()->put('festivos_preview', $resultado['filas']);
        $request->session()->put('festivos_errores', $resultado['errores']);

        return back();
    }

    public function cerrarPrevisualizacionFestivos(Request $request): RedirectResponse
    {
        $request->session()->forget(['festivos_preview', 'festivos_errores']);

        return back();
    }

    public function importarFestivos(Request $request, FestivosCsvService $csv): RedirectResponse
    {
        $filas = $request->session()->get('festivos_preview', []);
        if (! is_array($filas) || $filas === []) {
            return back()->with('aviso', 'Primero prepara un CSV para importar.');
        }

        $cantidad = $csv->importar($filas);
        $request->session()->forget(['festivos_preview', 'festivos_errores']);

        return back()->with('ok', 'Se han guardado '.$cantidad.' festivos.');
    }

    public function actualizarFestivo(Request $request, Festivo $festivo): RedirectResponse
    {
        $datos = $request->validate([
            'holiday_date' => ['required', 'date'],
            'name' => ['required', 'string', 'max:120'],
            'ambito' => ['required', 'in:estado,comunidad,pueblo'],
            'community' => ['nullable', 'string', 'max:120'],
            'centro' => ['nullable', 'string', 'exists:centros,name'],
        ]);

        $centro = $datos['centro'] ? Centro::query()->where('name', $datos['centro'])->firstOrFail() : null;
        if ($datos['ambito'] === 'pueblo' && $centro === null) {
            return back()->with('aviso', 'Un festivo local necesita un centro.');
        }
        if ($datos['ambito'] === 'comunidad' && trim((string) ($datos['community'] ?? '')) === '') {
            return back()->with('aviso', 'Un festivo autonómico necesita una autonomía.');
        }

        $festivo->update([
            'holiday_date' => $datos['holiday_date'],
            'name' => $datos['name'],
            'ambito' => $datos['ambito'],
            'community' => $datos['ambito'] === 'comunidad' ? trim((string) $datos['community']) : null,
            'centro_id' => $datos['ambito'] === 'pueblo' ? $centro?->id : null,
            'municipality' => $datos['ambito'] === 'pueblo' ? $centro?->municipality : '',
        ]);

        return back()->with('ok', 'Festivo actualizado.');
    }

    public function eliminarFestivo(Festivo $festivo): RedirectResponse
    {
        $festivo->delete();

        return back()->with('ok', 'Festivo eliminado.');
    }

    public function solicitar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'desde' => ['required', 'date', 'after_or_equal:today'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
            'nota' => ['nullable', 'string', 'max:500'],
        ], [
            'desde.required' => 'Elige el primer día.',
            'desde.date' => 'Elige el primer día.',
            'desde.after_or_equal' => 'El primer día no puede ser anterior a hoy.',
            'hasta.required' => 'Elige el último día.',
            'hasta.date' => 'Elige el último día.',
            'hasta.after_or_equal' => 'El último día tiene que ser el primero o uno posterior.',
            'nota.max' => 'La nota puede tener hasta 500 caracteres.',
        ]);

        $desde = Carbon::parse($datos['desde'])->startOfDay();
        $hasta = Carbon::parse($datos['hasta'])->startOfDay();
        $persona = $request->user();

        if (! $this->cupo->dentroDelPlazo($hasta)) {
            return back()->with('aviso', 'Tienes dos meses para cogerlas. A partir de ahí desaparecen.');
        }

        $nota = trim((string) ($datos['nota'] ?? ''));
        try {
            DB::transaction(function () use ($persona, $desde, $hasta, $nota): void {
                $actual = User::query()->whereKey($persona->id)->lockForUpdate()->firstOrFail();
                if (! $actual->estaEnPlantillaEl($desde) || ! $actual->estaEnPlantillaEl($hasta)) {
                    throw new ReglaJornada('Las fechas deben estar dentro del periodo laboral de la persona.');
                }
                $this->cupo->asegurarCobertura($actual, $desde, $hasta);
                $cuentan = $this->cupo->queCuentan($actual, $desde, $hasta);
                if ($cuentan->isEmpty()) {
                    throw new ReglaJornada('Esos días no cuentan: son fin de semana o festivo.');
                }
                if (! $this->cupo->cabe($actual, $desde, $hasta)) {
                    throw new ReglaJornada('El saldo disponible es insuficiente o todavía no está confirmado.');
                }
                $solapa = $actual->solicitudesVacaciones()
                    ->where('estado', SolicitudVacaciones::PENDIENTE)
                    ->whereDate('starts_on', '<=', $hasta->toDateString())
                    ->whereDate('ends_on', '>=', $desde->toDateString())
                    ->exists();
                $ocupada = $actual->ausencias()->whereDate('absence_date', '>=', $desde->toDateString())
                    ->whereDate('absence_date', '<=', $hasta->toDateString())->get()
                    ->contains(fn (Ausencia $ausencia): bool => $cuentan->contains($ausencia->absence_date->toDateString()));
                if ($solapa || $ocupada) {
                    throw new ReglaJornada('Esas fechas ya están pedidas.');
                }
                SolicitudVacaciones::create([
                    'user_id' => $actual->id,
                    'starts_on' => $desde->toDateString(),
                    'ends_on' => $hasta->toDateString(),
                    'nota' => $nota === '' ? null : $nota,
                    'estado' => SolicitudVacaciones::PENDIENTE,
                ]);
            }, 3);
        } catch (ReglaJornada $e) {
            return back()->with('aviso', $e->getMessage())->withInput();
        }

        return back()->with('ok', 'Enviada. El responsable la verá en Consultas.');
    }

    public function fallo(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'falla_date' => ['required', 'date'],
            'note' => ['required', 'string', 'max:200'],
        ], [
            'falla_date.required' => 'Elige el día.',
            'note.required' => 'Escribe qué falló.',
        ]);

        $this->jornada->falloComun($datos['falla_date'], trim($datos['note']));

        return back()->with('ok', 'Guardado. Ese aviso no entra en la cuenta de cada persona.');
    }

    /**
     * @param  Collection<int, DiaFestivo>  $festivos
     * @param  Collection<string, Ausencia>  $propias
     * @param  Collection<string, Jornada>  $jornadas
     * @param  Collection<string, mixed>  $pendientes
     * @return list<array{nombre: string, numero: int, dias: list<array<string, mixed>|null>}>
     */
    private function meses(int $anio, Collection $festivos, Collection $propias, Collection $jornadas, Collection $pendientes): array
    {
        $porDia = $festivos->keyBy(fn (DiaFestivo $festivo) => $festivo->holiday_date->toDateString());
        $hoy = now()->toDateString();
        $nombres = [
            'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
            'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
        ];
        $meses = [];

        foreach ($nombres as $indice => $nombre) {
            $inicio = Carbon::create($anio, $indice + 1, 1);
            $dias = array_fill(0, $inicio->dayOfWeekIso - 1, null);

            for ($dia = 1; $dia <= $inicio->daysInMonth; $dia++) {
                $fecha = Carbon::create($anio, $indice + 1, $dia)->toDateString();
                $jornada = $jornadas->get($fecha);
                $momento = Carbon::parse($fecha);
                $dias[] = [
                    'fecha' => $fecha,
                    'numero' => $dia,
                    'hoy' => $fecha === $hoy,
                    'finde' => $momento->isWeekend(),
                    'cuenta' => ! $momento->isWeekend() && ! $porDia->has($fecha),
                    'festivo' => $porDia->get($fecha),
                    'ausencia' => $propias->get($fecha),
                    'pendiente' => $pendientes->has($fecha),
                    'registro' => $jornada && $fecha < $hoy ? route('registro.show', $jornada) : null,
                ];
            }

            $meses[] = ['nombre' => $nombre, 'numero' => $indice + 1, 'dias' => $dias];
        }

        return $meses;
    }

    /**
     * @param  array{dias: list<array<string, mixed>|null>}  $mes
     * @return list<array{fecha: string, dia: int, diaSemana: string, titulo: string, subtitulo: string, clase: string}>
     */
    private function eventosDelMes(array $mes): array
    {
        $eventos = [];

        foreach ($mes['dias'] as $dia) {
            if ($dia === null) {
                continue;
            }

            $fecha = Carbon::parse($dia['fecha']);
            $festivo = $dia['festivo'];
            $ausencia = $dia['ausencia'];

            if ($festivo instanceof DiaFestivo) {
                $eventos[] = [
                    'fecha' => $dia['fecha'],
                    'dia' => $fecha->day,
                    'diaSemana' => mb_strtoupper($fecha->locale('es')->isoFormat('ddd')),
                    'titulo' => $festivo->name,
                    'subtitulo' => $festivo->etiqueta(),
                    'clase' => $festivo->ambito,
                ];
            }

            if ($ausencia instanceof Ausencia) {
                $eventos[] = [
                    'fecha' => $dia['fecha'],
                    'dia' => $fecha->day,
                    'diaSemana' => mb_strtoupper($fecha->locale('es')->isoFormat('ddd')),
                    'titulo' => $ausencia->tipoTexto(),
                    'subtitulo' => 'Ausencia prevista',
                    'clase' => $ausencia->tipo,
                ];
            } elseif ($dia['pendiente']) {
                $eventos[] = [
                    'fecha' => $dia['fecha'],
                    'dia' => $fecha->day,
                    'diaSemana' => mb_strtoupper($fecha->locale('es')->isoFormat('ddd')),
                    'titulo' => 'Vacaciones',
                    'subtitulo' => 'Pendiente de decidir',
                    'clase' => 'pendiente',
                ];
            }
        }

        return $eventos;
    }
}
