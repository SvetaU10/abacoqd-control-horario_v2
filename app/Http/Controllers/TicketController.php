<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TicketController extends Controller
{
    private const TICKETS_VISIBLES = 5;

    public function __construct(private TicketService $tickets) {}

    public function index(Request $request): View
    {
        $filtro = $request->query('estado');
        $filtro = in_array($filtro, ['todos', Ticket::ABIERTO, Ticket::EN_CURSO, Ticket::RESUELTO], true)
            ? $filtro
            : 'todos';

        $consulta = Ticket::query()
            ->whereBelongsTo($request->user(), 'autor')
            ->with('autor')
            ->latest('id');
        $conteo = Ticket::query()->whereBelongsTo($request->user(), 'autor');

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

        return view('tickets.index', [
            'tickets' => $tickets,
            'filtro' => $filtro,
            'pendientes' => $abiertos + $enCurso,
            'todos' => $abiertos + $enCurso + $resueltos,
            'mostrarTodas' => $mostrarTodas,
            'hayMas' => $mostrarTodas || $totalFiltro > self::TICKETS_VISIBLES,
            'desde' => $this->paginaDeOrigen($request->query('desde')),
        ]);
    }

    public function create(Request $request): View
    {
        return view('tickets.create', [
            'desde' => $this->paginaDeOrigen($request->query('desde')),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'asunto' => ['required', 'string', 'max:120', 'not_regex:/[\r\n]/'],
            'descripcion' => ['required', 'string', 'max:500'],
            'desde' => ['nullable', 'string', 'max:200'],
        ], [
            'asunto.required' => 'Escribe un asunto corto.',
            'asunto.max' => 'El asunto es demasiado largo. Máximo 120 letras.',
            'asunto.not_regex' => 'El asunto va en una sola línea.',
            'descripcion.required' => 'Cuenta qué ha pasado.',
            'descripcion.max' => 'La descripción puede tener hasta 500 caracteres.',
        ]);

        $ticket = $this->tickets->abrir(
            $request->user(),
            trim($datos['asunto']),
            trim($datos['descripcion']),
            $this->paginaDeOrigen($datos['desde'] ?? null),
        );

        return redirect()->route('tickets.show', $ticket)
            ->with('ok', 'Guardado. El técnico ya tiene tu ticket.');
    }

    public function show(Request $request, Ticket $ticket): View
    {
        $this->permitir($request, $ticket);

        if ($ticket->user_id === $request->user()->id && $ticket->respuesta_vista_at === null) {
            $ticket->update(['respuesta_vista_at' => now()]);
        }

        return view('tickets.show', [
            'ticket' => $ticket->load(['autor', 'mensajes.autor']),
            'esTecnico' => $request->user()->esTecnico(),
        ]);
    }

    public function mensaje(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->permitir($request, $ticket);

        $datos = $request->validate([
            'cuerpo' => ['required', 'string', 'max:2000'],
        ], [
            'cuerpo.required' => 'Escribe el mensaje.',
            'cuerpo.max' => 'El mensaje es demasiado largo. Máximo 2000 letras.',
        ]);

        $this->tickets->responder($ticket, $request->user(), trim($datos['cuerpo']));

        return redirect()->route('tickets.show', $ticket)->with('ok', 'Guardado. Mensaje enviado.');
    }

    public function estado(Request $request, Ticket $ticket): RedirectResponse
    {
        $datos = $request->validate([
            'estado' => ['required', Rule::in(array_keys(Ticket::ESTADOS))],
        ], [
            'estado.required' => 'Elige un estado.',
            'estado.in' => 'Ese estado no existe.',
        ]);

        $this->tickets->cambiarEstado($ticket, $datos['estado']);

        return redirect()->route('tickets.show', $ticket)
            ->with('ok', 'Guardado. El ticket está '.mb_strtolower($ticket->fresh()->etiquetaDeEstado()).'.');
    }

    /**
     * Solo quien lo abrió y el técnico. Para el resto el ticket no existe.
     */
    private function permitir(Request $request, Ticket $ticket): void
    {
        abort_unless(
            $request->user()->esTecnico() || $ticket->user_id === $request->user()->id,
            404,
        );
    }

    /**
     * La página desde la que se abrió el ticket. Solo rutas de esta aplicación.
     */
    private function paginaDeOrigen(mixed $pagina): ?string
    {
        if (! is_string($pagina) || ! str_starts_with($pagina, '/') || str_starts_with($pagina, '//') || strlen($pagina) > 200) {
            return null;
        }

        return preg_match('/[\x00-\x1F\x7F]/', $pagina) === 1 ? null : $pagina;
    }
}
