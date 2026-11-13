<?php

namespace App\Http\Controllers;

use App\Models\Centro;
use App\Models\ImportacionCalendario;
use App\Services\CalendarioMunicipal;
use App\Services\NagerFestivos;
use App\Services\VigenciasCalendario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;

class ImportacionCalendarioController extends Controller
{
    private const ETIQUETAS = [
        'cargado' => 'Calendario guardado', 'actualizado' => 'Calendario actualizado',
        'sin_cambios' => 'Al día', 'parcial' => 'Faltan algunas fiestas',
        'no_disponible' => 'Aún sin datos', 'pendiente_revision' => 'Revisa las fechas',
        'conflicto' => 'Hay fechas por revisar', 'configuracion_modificada' => 'Carga el nuevo municipio',
        'contexto_manual' => 'Festivos anteriores', 'error' => 'No se pudo consultar',
    ];

    public function __construct(private CalendarioMunicipal $calendario) {}

    public function mostrar(Request $request, Centro $centro, VigenciasCalendario $vigencias, NagerFestivos $festivos): View
    {
        $anio = filter_var($request->old('anio', $request->query('anio', now()->year)), FILTER_VALIDATE_INT);
        if ($anio === false || $anio < 2020 || $anio > now()->year + 5) {
            $anio = now()->year;
        }

        $ultima = $this->calendario->ultima($centro, $anio);
        $activo = $this->calendario->activo($centro, $anio);
        $tramos = $vigencias->delAnio($centro, $anio);
        $modoPorDefecto = $tramos->isNotEmpty() && $anio === now()->year ? 'fecha' : 'anio';
        $modoVigencia = $request->old('vigencia_modo', $modoPorDefecto);
        if (! in_array($modoVigencia, ['anio', 'fecha'], true)) {
            $modoVigencia = $modoPorDefecto;
        }
        $fechaPorDefecto = $anio === now()->year ? now()->toDateString() : $anio.'-01-01';
        $fechaVigencia = $request->old('vigente_desde', $fechaPorDefecto);
        $importacionDeLaSede = $ultima && $ultima->estado !== 'configuracion_modificada'
            && ($centro->municipio_ine === null || $ultima->municipio_ine === $centro->municipio_ine);
        $municipio = $importacionDeLaSede ? [
            'ine' => $ultima->municipio_ine,
            'nombre' => $ultima->municipio,
            'provincia' => $ultima->provincia,
            'comunidad' => $ultima->comunidad,
        ] : null;
        $avisoCatalogo = null;
        $ineAnterior = $request->old('municipio_ine');
        if (is_string($ineAnterior) && preg_match('/^\d{5}$/D', $ineAnterior)) {
            try {
                $municipio = $this->calendario->municipio($ineAnterior);
            } catch (InvalidArgumentException $error) {
                $avisoCatalogo = $error->getMessage();
            } catch (RuntimeException) {
                $avisoCatalogo = 'No se puede recuperar el municipio seleccionado. Inténtalo de nuevo.';
            }
        }

        if ($municipio === null) {
            try {
                $municipio = $centro->municipio_ine
                    ? $this->calendario->municipio($centro->municipio_ine)
                    : collect($this->calendario->municipios($centro->municipality))
                        ->first(fn (array $fila) => Str::lower(Str::ascii($fila['nombre'])) === Str::lower(Str::ascii($centro->municipality))
                            && ($centro->comunidad_codigo === null || $fila['comunidad_codigo'] === $centro->comunidad_codigo));
            } catch (InvalidArgumentException) {
                $avisoCatalogo = 'Selecciona de nuevo el municipio de esta sede.';
            } catch (RuntimeException) {
                $avisoCatalogo = 'El buscador de municipios no está disponible ahora. Inténtalo de nuevo en unos minutos.';
            }
        }

        return view('gestion.calendario', [
            'centro' => $centro,
            'centros' => Centro::query()->orderBy('name')->get(),
            'anio' => $anio,
            'ultima' => $ultima,
            'resumenConsulta' => $ultima ? $this->resumen($ultima) : null,
            'etiquetasConsulta' => self::ETIQUETAS,
            'activo' => $activo,
            'tramos' => $tramos,
            'diasAplicados' => $festivos->delAnio($centro, $anio),
            'modoVigencia' => $modoVigencia,
            'fechaVigencia' => is_string($fechaVigencia) ? $fechaVigencia : $fechaPorDefecto,
            'municipioElegido' => $municipio,
            'avisoCatalogo' => $avisoCatalogo,
            'historial' => ImportacionCalendario::query()->where('centro_id', $centro->id)
                ->where('anio', $anio)->latest('id')->limit(10)->get(),
        ]);
    }

    public function municipios(Request $request): JsonResponse
    {
        $datos = $request->validate(['buscar' => ['required', 'string', 'min:2', 'max:120']]);

        try {
            $municipios = $this->calendario->municipios($datos['buscar']);

            return response()->json(['municipios' => array_slice($municipios, 0, 25), 'hay_mas' => count($municipios) > 25]);
        } catch (RuntimeException) {
            return response()->json(['message' => 'No se puede consultar el catálogo ahora. Inténtalo de nuevo.'], 503);
        }
    }

    public function cargar(Request $request, Centro $centro): RedirectResponse
    {
        $datos = $request->validate([
            'anio' => ['required', 'integer', 'between:2020,'.(now()->year + 5)],
            'municipio_ine' => ['required', 'string', 'regex:/^\d{5}$/'],
            'vigencia_modo' => ['nullable', 'in:anio,fecha'],
            'vigente_desde' => ['exclude_if:vigencia_modo,anio', 'nullable', 'required_if:vigencia_modo,fecha', 'date_format:Y-m-d'],
        ], [
            'anio.required' => 'Indica el año que quieres cargar.',
            'anio.integer' => 'El año debe ser un número entero.',
            'anio.between' => 'Elige un año entre 2020 y '.(now()->year + 5).'.',
            'municipio_ine.required' => 'Selecciona un municipio de la lista.',
            'municipio_ine.regex' => 'Selecciona un municipio válido de la lista.',
            'vigencia_modo.in' => 'Elige todo el año o una fecha de inicio.',
            'vigente_desde.required_if' => 'Indica desde qué día se usará este municipio.',
            'vigente_desde.date_format' => 'Indica una fecha válida.',
        ]);
        $modo = $datos['vigencia_modo'] ?? (isset($datos['vigente_desde']) ? 'fecha' : 'anio');
        $desde = $modo === 'anio' ? $datos['anio'].'-01-01' : $datos['vigente_desde'];
        if ($desde < $datos['anio'].'-01-01' || $desde > $datos['anio'].'-12-31') {
            throw ValidationException::withMessages(['vigente_desde' => 'Elige una fecha del año seleccionado.']);
        }

        try {
            $resultado = $this->calendario->cargar($centro, (int) $datos['anio'], $datos['municipio_ine'], $request->user(), desde: $desde);
        } catch (InvalidArgumentException $error) {
            return back()->withInput()->withErrors(['municipio_ine' => $error->getMessage()]);
        }

        return redirect()->route('gestion.calendario.mostrar', ['centro' => $centro, 'anio' => $datos['anio']])
            ->with(in_array($resultado->estado, ['cargado', 'actualizado', 'sin_cambios'], true) ? 'ok' : 'aviso', $this->resumen($resultado));
    }

    public function confirmar(Request $request, Centro $centro, ImportacionCalendario $importacion): RedirectResponse
    {
        abort_unless((int) $importacion->centro_id === $centro->id, 404);
        $request->validate(['revision' => ['accepted']], ['revision.accepted' => 'Confirma que has revisado las fuentes oficiales.']);

        try {
            $resultado = $this->calendario->confirmar($importacion, $request->user());
        } catch (InvalidArgumentException|RuntimeException $error) {
            return back()->with('aviso', $error->getMessage());
        }

        return redirect()->route('gestion.calendario.mostrar', ['centro' => $centro, 'anio' => $importacion->anio])
            ->with('ok', $this->resumen($resultado));
    }

    private function resumen(ImportacionCalendario $consulta): string
    {
        $desde = $consulta->vigente_desde?->format('d/m/Y') ?? '01/01/'.$consulta->anio;

        return match ($consulta->estado) {
            'cargado', 'actualizado' => 'Los festivos de '.$consulta->municipio.' están guardados desde el '.$desde.'.',
            'sin_cambios' => 'El calendario ya estaba al día. No hay cambios que guardar.',
            'parcial' => 'Todavía faltan datos para completar este calendario. El anterior se conserva.',
            'no_disponible' => 'Aún no hay datos disponibles para ese municipio y año. Puedes volver a buscar más adelante.',
            'pendiente_revision' => 'Hemos encontrado las fiestas. Revisa las fechas y sus fuentes antes de guardarlas.',
            'conflicto' => 'Las fuentes muestran fechas distintas. El calendario anterior se conserva.',
            'configuracion_modificada' => 'La sede ha cambiado de municipio. Busca su calendario de nuevo.',
            'contexto_manual' => 'Se conservan los festivos que habías añadido para esas fechas.',
            default => 'No se ha podido consultar el calendario. Tus datos se conservan. Puedes intentarlo más tarde.',
        };
    }
}
