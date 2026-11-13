<?php

namespace App\Http\Controllers;

use App\Exceptions\ReglaJornada;
use App\Models\Aviso;
use App\Models\Correccion;
use App\Models\Incidencia;
use App\Models\Jornada;
use App\Models\Tramo;
use App\Models\User;
use App\Services\JornadaService;
use App\Services\TicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

class JornadaController extends Controller
{
    public function __construct(private JornadaService $jornada, private TicketService $tickets) {}

    public function index(Request $request): View
    {
        $clave = 'festivo.'.$request->user()->id.'.'.now()->toDateString();

        return view('jornada.index', [
            ...$this->jornada->pantalla(
                $request->user(),
                $request->session()->get($clave),
            ),
            'puedeBorrarPrueba' => $this->puedeBorrarPrueba($request->user()->email),
        ]);
    }

    public function borrarPrueba(Request $request): RedirectResponse
    {
        return $this->borrarFichajeDePrueba($request, now()->toDateString());
    }

    public function borrarAyer(Request $request): RedirectResponse
    {
        return $this->borrarFichajeDePrueba($request, now()->subDay()->toDateString());
    }

    public function borrarHaceTresDias(Request $request): RedirectResponse
    {
        return $this->borrarFichajeDePrueba($request, now()->subDays(3)->toDateString());
    }

    private function borrarFichajeDePrueba(Request $request, string $fecha): RedirectResponse
    {
        if (! $this->puedeBorrarPrueba($request->user()->email)) {
            abort(403);
        }

        DB::transaction(function () use ($request, $fecha) {
            $jornada = Jornada::query()
                ->where('user_id', $request->user()->id)
                ->whereDate('work_date', $fecha)
                ->first();

            if ($jornada) {
                $tramos = Tramo::query()->where('jornada_id', $jornada->id)->pluck('id');
                Correccion::query()->whereIn('tramo_id', $tramos)->delete();
                Tramo::query()->where('jornada_id', $jornada->id)->delete();
                $jornada->delete();
            }

            Aviso::query()
                ->where('user_id', $request->user()->id)
                ->whereDate('work_date', $fecha)
                ->delete();
        });

        $request->session()->forget('festivo.'.$request->user()->id.'.'.$fecha);

        $this->jornada->avisarSiFaltaEntrada($request->user(), $fecha);

        return back();
    }

    private function puedeBorrarPrueba(string $email): bool
    {
        if (! config('app.demo') || ! app()->environment('local', 'testing')) {
            return false;
        }

        return in_array($email, [
            'ana.lopez@abaco.test',
            'marta.ruiz@abaco.test',
            'carmen.ortega@abaco.test',
        ], true);
    }

    public function entrar(Request $request): RedirectResponse
    {
        $this->normalizarHora($request);
        if (! $request->filled('hora') && ! $request->filled('modo')) {
            if ($this->jornada->horaInicioSiEsTemprano($request->user()) && ! $request->boolean('confirmar_temprano')) {
                return back()->with('confirma_temprano', true);
            }
        }

        $datos = $request->validate([
            'modo' => ['nullable', 'in:ahora,prevista,otra'],
            'hora' => ['nullable', 'required_if:modo,otra', 'date_format:H:i'],
        ], [
            'modo.in' => 'Elige cómo empezó tu jornada.',
            'hora.required' => 'Elige la hora de entrada.',
            'hora.date_format' => 'Elige una hora.',
        ]);

        return $this->hacer(fn () => $this->jornada->entradaSegunMotivo(
            $request->user(),
            $datos['modo'] ?? null,
            $datos['hora'] ?? null,
        ));
    }

    public function salir(Request $request): RedirectResponse
    {
        return $this->hacer(fn () => $this->jornada->salir($request->user()));
    }

    public function pausar(Request $request): RedirectResponse
    {
        return $this->hacer(fn () => $this->jornada->pausar($request->user()));
    }

    public function volver(Request $request): RedirectResponse
    {
        return $this->hacer(fn () => $this->jornada->volver($request->user()));
    }

    public function yaEstaba(Request $request): RedirectResponse
    {
        $this->normalizarHora($request);
        $datos = $request->validate([
            'hora' => ['required', 'date_format:H:i'],
        ], [
            'hora.required' => 'Elige una hora.',
            'hora.date_format' => 'Elige una hora.',
        ]);

        return $this->hacer(fn () => $this->jornada->yaEstaba($request->user(), $datos['hora']));
    }

    public function completar(Request $request): RedirectResponse
    {
        $this->normalizarHora($request);
        $datos = $request->validate([
            'hora' => ['required', 'date_format:H:i'],
        ], [
            'hora.required' => 'Elige una hora.',
            'hora.date_format' => 'Elige una hora.',
        ]);

        return $this->hacer(fn () => $this->jornada->completarOlvido($request->user(), $datos['hora']));
    }

    public function reunion(Request $request): RedirectResponse
    {
        return $this->hacer(fn () => $this->jornada->reunion($request->user()));
    }

    public function cerrar(Request $request): RedirectResponse
    {
        $this->normalizarHora($request);
        $datos = $request->validate([
            'hora' => ['required', 'date_format:H:i'],
        ], [
            'hora.required' => 'Elige una hora.',
            'hora.date_format' => 'Elige una hora.',
        ]);

        return $this->hacer(fn () => $this->jornada->cerrarALas($request->user(), $datos['hora']));
    }

    public function seguir(Request $request): RedirectResponse
    {
        return $this->hacer(fn () => $this->jornada->seguir($request->user()));
    }

    public function crearIncidencia(Request $request): View|RedirectResponse
    {
        $usuario = $request->user();

        if ($usuario->esJefa()) {
            return redirect()->route('incidencias.index');
        }

        $incidencias = Incidencia::query()
            ->with('autor', 'destinatario')
            ->where(function ($consulta) use ($usuario) {
                $consulta->where('user_id', $usuario->id)
                    ->orWhere('destinatario_id', $usuario->id);
            })
            ->orderByRaw("case when estado = '".Incidencia::RESUELTA."' then 1 else 0 end")
            ->latest('id')
            ->get();
        $pedido = $request->query('incidencia');
        $seleccion = is_string($pedido) && ctype_digit($pedido)
            ? $incidencias->firstWhere('id', (int) $pedido)
            : null;
        $seleccion?->load('mensajes.autor');

        if ($seleccion && $seleccion->destinatario_id === $usuario->id && $seleccion->vista_at === null) {
            $seleccion->update(['vista_at' => now()]);
        }

        $nuevas = $incidencias->filter(fn (Incidencia $incidencia) => $incidencia->destinatario_id === $usuario->id
            && $incidencia->vista_at === null
            && ! $incidencia->estaResuelta());
        $historial = $incidencias->reject(fn (Incidencia $incidencia) => $nuevas->contains('id', $incidencia->id));

        return view('jornada.incidencia', [
            'incidencias' => $incidencias,
            'nuevas' => $nuevas,
            'historial' => $historial,
            'seleccion' => $seleccion,
        ]);
    }

    public function incidencia(Request $request): RedirectResponse
    {
        $request->merge(['motivo' => trim((string) $request->input('motivo', ''))]);

        $datos = $request->validate([
            'motivo' => Incidencia::reglasDeMotivo(),
            'destinatario' => [$request->user()->esJefa() ? 'required' : 'nullable', 'integer', 'exists:users,id'],
        ], [
            ...Incidencia::mensajesDeMotivo(),
            'destinatario.required' => 'Elige a quién va dirigida.',
            'destinatario.exists' => 'Elige a una persona del equipo.',
        ]);

        $destinatarioId = null;

        if ($request->user()->esJefa()) {
            $destinatario = User::query()->conJornada()->whereKey($datos['destinatario'])->where('active', true)->first();

            if (! $destinatario || $destinatario->esJefa()) {
                return back()->withErrors(['destinatario' => 'Elige a una persona del equipo.'])->withInput();
            }

            $destinatarioId = $destinatario->id;
        }

        Incidencia::create([
            'user_id' => $request->user()->id,
            'destinatario_id' => $destinatarioId,
            'cuerpo' => $datos['motivo'],
            'estado' => Incidencia::PENDIENTE,
        ]);

        if ($destinatarioId !== null) {
            return redirect()->route('incidencias.index')->with('ok', 'Enviada. '.$destinatario->name.' ya tiene tu incidencia.');
        }

        try {
            $this->tickets->abrir(
                $request->user(),
                'Incidencia de conexión',
                trim($datos['motivo']),
                '/jornada/incidencia',
            );
        } catch (Throwable $error) {
            report($error);

            return redirect()->route('jornada.incidencia.crear')->with('ok', 'Enviada. El responsable ya tiene tu incidencia.');
        }

        return redirect()->route('jornada.incidencia.crear')->with('ok', 'Enviada. El responsable ya tiene tu incidencia.');
    }

    public function festivo(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'respuesta' => ['required', 'in:si,no'],
        ]);

        $request->session()->put(
            'festivo.'.$request->user()->id.'.'.now()->toDateString(),
            $datos['respuesta'],
        );

        return back()->with('ok', $datos['respuesta'] === 'si'
            ? 'Guardado. Puedes registrar el trabajo de hoy.'
            : 'Guardado. Hoy no hay trabajo registrado.');
    }

    private function hacer(callable $accion): RedirectResponse
    {
        try {
            return back()->with('ok', $accion());
        } catch (ReglaJornada $e) {
            return back()->with('aviso', $e->getMessage());
        }
    }

    private function normalizarHora(Request $request, string $campo = 'hora'): void
    {
        $hora = $request->input($campo);
        if (is_string($hora) && strlen($hora) >= 5) {
            $request->merge([$campo => substr($hora, 0, 5)]);
        }
    }
}
