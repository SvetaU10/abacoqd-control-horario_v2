<?php

namespace App\Http\Controllers;

use App\Models\AccionTecnica;
use App\Models\Ticket;
use App\Models\User;
use App\Services\SistemaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TecnicoController extends Controller
{
    private const TICKETS_VISIBLES = 5;

    public function __construct(private SistemaService $sistema) {}

    public function index(): View
    {
        return view('tecnico.index', [
            'base' => $this->sistema->baseDeDatos(),
            'entorno' => $this->sistema->entorno(),
            'copias' => $this->sistema->copias(),
            'copiasRestauracion' => $this->sistema->copias(true),
            'acciones' => AccionTecnica::query()->with('autor')->latest('id')->limit(10)->get(),
            'ticketsPendientes' => Ticket::query()->where('estado', '!=', Ticket::RESUELTO)->count(),
        ]);
    }

    /**
     * Todos los tickets. Por defecto, los que siguen abiertos.
     */
    public function tickets(Request $request): View
    {
        $filtro = $request->query('estado');
        $filtro = in_array($filtro, ['todos', Ticket::ABIERTO, Ticket::EN_CURSO, Ticket::RESUELTO], true)
            ? $filtro
            : Ticket::ABIERTO;
        $trabajadorId = $request->integer('trabajador');
        $trabajador = $trabajadorId > 0
            ? User::query()->whereKey($trabajadorId)->first()
            : null;

        $consulta = Ticket::query()->with('autor')->latest('id');
        $conteo = Ticket::query();

        if ($trabajador) {
            $consulta->where('user_id', $trabajador->id);
            $conteo->where('user_id', $trabajador->id);
        }

        if ($filtro !== 'todos') {
            $consulta->where('estado', $filtro);
        }

        $abiertos = (clone $conteo)->where('estado', Ticket::ABIERTO)->count();
        $enCurso = (clone $conteo)->where('estado', Ticket::EN_CURSO)->count();
        $resueltos = (clone $conteo)->where('estado', Ticket::RESUELTO)->count();
        $totalFiltro = match ($filtro) {
            Ticket::ABIERTO => $abiertos,
            Ticket::EN_CURSO => $enCurso,
            Ticket::RESUELTO => $resueltos,
            default => $abiertos + $enCurso + $resueltos,
        };
        $mostrarTodas = $request->boolean('todas_incidencias');
        $tickets = $mostrarTodas
            ? $consulta->paginate(25)->withQueryString()
            : $consulta->limit(self::TICKETS_VISIBLES)->get();

        return view('tecnico.tickets', [
            'tickets' => $tickets,
            'filtro' => $filtro,
            'trabajador' => $trabajador,
            'trabajadores' => User::query()
                ->orderBy('name')
                ->get(['id', 'name']),
            'abiertos' => $abiertos,
            'enCurso' => $enCurso,
            'resueltos' => $resueltos,
            'pendientes' => $abiertos + $enCurso,
            'todos' => $abiertos + $enCurso + $resueltos,
            'mostrarTodas' => $mostrarTodas,
            'hayMas' => $mostrarTodas || $totalFiltro > self::TICKETS_VISIBLES,
        ]);
    }

    public function copia(Request $request): RedirectResponse
    {
        try {
            $nombre = $this->sistema->hacerCopia($request->user());
        } catch (RuntimeException $error) {
            return back()->with('aviso', $error->getMessage());
        }

        return back()->with('ok', 'Guardado. Copia '.$nombre.'.');
    }

    public function exportar(Request $request): BinaryFileResponse|RedirectResponse
    {
        try {
            $nombre = $this->sistema->hacerCopia($request->user());
            $ruta = $this->sistema->rutaDeCopia($nombre);
        } catch (RuntimeException $error) {
            return back()->with('aviso', $error->getMessage());
        }

        return response()->download($ruta, $nombre, [
            'Content-Type' => 'application/x-sqlite3',
        ]);
    }

    public function actualizar(Request $request): RedirectResponse
    {
        $request->validate([
            'confirmo' => ['accepted'],
        ], [
            'confirmo.accepted' => 'Marca la casilla para confirmar que quieres actualizar.',
        ]);

        try {
            $mensaje = $this->sistema->actualizar($request->user());
        } catch (RuntimeException $error) {
            return back()->with('aviso', $error->getMessage());
        }

        return back()->with('ok', $mensaje);
    }

    public function restaurar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'confirmo' => ['accepted'],
            'copia' => ['nullable', 'string', 'max:255'],
            'archivo' => ['nullable', 'file', 'max:102400'],
        ], [
            'confirmo.accepted' => 'Marca la casilla para confirmar que quieres sustituir la base.',
            'archivo.file' => 'Elige un archivo de base de datos.',
            'archivo.max' => 'Ese archivo es demasiado grande.',
        ]);

        $rutaSubida = $request->file('archivo')?->getRealPath() ?: null;

        try {
            $mensaje = $this->sistema->restaurar(
                $request->user(),
                $datos['copia'] ?? null,
                is_string($rutaSubida) ? $rutaSubida : null,
            );
        } catch (RuntimeException $error) {
            return back()->with('aviso', $error->getMessage())->with('restaurar', true);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->flash('ok', $mensaje);

        return redirect()->route('login');
    }
}
