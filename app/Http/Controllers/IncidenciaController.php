<?php

namespace App\Http\Controllers;

use App\Models\Incidencia;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IncidenciaController extends Controller
{
    public function index(Request $request): View
    {
        $persona = $request->query('persona');
        $personaId = is_string($persona) && ctype_digit($persona) ? (int) $persona : null;
        $desde = $this->fecha($request->query('desde'));
        $hasta = $this->fecha($request->query('hasta'));

        if ($personaId !== null && ! User::query()->conJornada()->whereKey($personaId)->exists()) {
            $personaId = null;
        }

        $archivo = $request->boolean('archivo');

        $incidencias = Incidencia::query()
            ->with('autor', 'destinatario')
            ->when($archivo, fn ($q) => $q->where('estado', Incidencia::RESUELTA))
            ->when(! $archivo, fn ($q) => $q->where('estado', '!=', Incidencia::RESUELTA))
            ->when($personaId !== null, fn ($q) => $q->where(function ($consulta) use ($personaId) {
                $consulta->where('user_id', $personaId)->orWhere('destinatario_id', $personaId);
            }))
            ->when($desde !== '', fn ($q) => $q->whereDate('created_at', '>=', $desde))
            ->when($hasta !== '', fn ($q) => $q->whereDate('created_at', '<=', $hasta))
            ->latest('id')
            ->get();
        $nuevas = $archivo
            ? collect()
            : Incidencia::query()
                ->with('autor', 'destinatario')
                ->whereNull('destinatario_id')
                ->whereNull('vista_at')
                ->whereIn('estado', [Incidencia::PENDIENTE, Incidencia::ABIERTA])
                ->when($personaId !== null, fn ($q) => $q->where(function ($consulta) use ($personaId) {
                    $consulta->where('user_id', $personaId)->orWhere('destinatario_id', $personaId);
                }))
                ->when($desde !== '', fn ($q) => $q->whereDate('created_at', '>=', $desde))
                ->when($hasta !== '', fn ($q) => $q->whereDate('created_at', '<=', $hasta))
                ->latest('id')
                ->get();

        return view('incidencias.index', [
            'incidencias' => $incidencias,
            'nuevas' => $nuevas,
            'personas' => User::query()->conJornada()->orderBy('name')->get(),
            'equipo' => User::query()->conJornada()->where('active', true)->whereNotIn('role', User::ROLES_JEFE)->orderBy('name')->get(['id', 'name']),
            'personaId' => $personaId,
            'desde' => $desde,
            'hasta' => $hasta,
            'hoy' => now()->toDateString(),
            'archivo' => $archivo,
        ]);
    }

    public function show(Incidencia $incidencia): View
    {
        if ($incidencia->destinatario_id === null && $incidencia->vista_at === null) {
            $incidencia->update(['vista_at' => now()]);
        }

        return view('incidencias.show', [
            'incidencia' => $incidencia->load('autor', 'destinatario', 'mensajes.autor'),
        ]);
    }

    public function mensaje(Request $request, Incidencia $incidencia): RedirectResponse
    {
        $usuario = $request->user();
        abort_unless(
            $usuario->esJefa() || $incidencia->user_id === $usuario->id || $incidencia->destinatario_id === $usuario->id,
            403,
        );

        if ($incidencia->estaResuelta()) {
            return back()->with('aviso', 'Esta incidencia está resuelta. El chat está cerrado.');
        }

        $datos = $request->validate([
            'cuerpo' => ['required', 'string', 'max:1000'],
        ], [
            'cuerpo.required' => 'Escribe un mensaje.',
            'cuerpo.max' => 'El mensaje puede tener hasta 1000 caracteres.',
        ]);

        $incidencia->mensajes()->create([
            'user_id' => $usuario->id,
            'cuerpo' => trim($datos['cuerpo']),
        ]);

        $incidencia->pasarAEnCurso();

        return back()->with('ok', 'Mensaje enviado.');
    }

    public function enCurso(Incidencia $incidencia): RedirectResponse
    {
        if ($incidencia->estaResuelta()) {
            return back()->with('aviso', 'Esa incidencia ya está resuelta.');
        }

        if ($incidencia->estaEnCurso()) {
            return back()->with('aviso', 'Esa incidencia ya está en curso.');
        }

        $incidencia->pasarAEnCurso();

        return back()->with('ok', 'En curso.');
    }

    public function resolver(Request $request, Incidencia $incidencia): RedirectResponse
    {
        if ($incidencia->estaResuelta()) {
            return back()->with('aviso', 'Esa incidencia ya está resuelta.');
        }

        $incidencia->update([
            'estado' => Incidencia::RESUELTA,
            'resuelta_at' => now(),
        ]);

        return redirect()
            ->route('incidencias.index')
            ->with('ok', 'Resuelta. Queda en el archivo.');
    }

    private function fecha(mixed $valor): string
    {
        return is_string($valor) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) === 1 ? $valor : '';
    }
}
