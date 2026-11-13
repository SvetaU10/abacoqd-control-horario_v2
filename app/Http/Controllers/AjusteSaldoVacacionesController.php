<?php

namespace App\Http\Controllers;

use App\Exceptions\ReglaJornada;
use App\Models\AjusteSaldoVacaciones;
use App\Models\User;
use App\Services\AjustarSaldoVacaciones;
use App\Services\CupoVacaciones;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AjusteSaldoVacacionesController extends Controller
{
    public function edit(Request $request, User $user, CupoVacaciones $cupo): View
    {
        abort_unless($request->user()->esJefa() && $user->registraJornada(), 403);
        $anio = filter_var($request->query('anio', now()->year), FILTER_VALIDATE_INT);
        abort_unless($anio !== false && $anio >= 2000 && $anio <= 2100, 422);

        $ajustes = AjusteSaldoVacaciones::query()->with('actor')->where('user_id', $user->id)->where('anio', $anio)->latest('id')->get();
        $problemaCobertura = null;
        try {
            $cupo->asegurarCoberturaParaSaldo($user, $anio);
        } catch (ReglaJornada $e) {
            $problemaCobertura = $e->getMessage();
        }

        return view('vacaciones.ajustar-saldo', [
            'persona' => $user, 'anio' => $anio,
            'aprobados' => $problemaCobertura ? null : $cupo->aprobados($user, $anio),
            'pendientes' => $problemaCobertura ? null : $cupo->pendientes($user, $anio),
            'restantes' => $problemaCobertura ? null : $cupo->restantes($user, $anio),
            'problemaCobertura' => $problemaCobertura,
            'ajustes' => $ajustes, 'ultimoAjuste' => $ajustes->first()?->id ?? 0,
            'operacion' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, User $user, AjustarSaldoVacaciones $servicio): RedirectResponse
    {
        abort_unless($request->user()->esJefa() && $user->registraJornada(), 403);
        $datos = $request->validate([
            'anio' => ['required', 'integer', 'between:2000,2100'],
            'saldo' => ['required', 'integer', 'between:0,366'],
            'aprobados_esperados' => ['required', 'integer', 'min:0'],
            'ultimo_ajuste_esperado' => ['required', 'integer', 'min:0'],
            'motivo' => ['required', 'string', 'max:500'],
            'operacion' => ['required', 'uuid'],
        ]);
        try {
            $servicio->ajustar($request->user(), $user, (int) $datos['anio'], (int) $datos['saldo'],
                (int) $datos['aprobados_esperados'], (int) $datos['ultimo_ajuste_esperado'], $datos['motivo'], $datos['operacion']);
        } catch (ReglaJornada|UniqueConstraintViolationException $e) {
            return back()->withInput()->with('aviso', $e instanceof ReglaJornada ? $e->getMessage() : 'Esta operación ya se ha registrado.');
        }

        return redirect()->route('equipo.vacaciones.saldo', ['user' => $user, 'anio' => $datos['anio']])->with('ok', 'Saldo confirmado y anotado en el historial.');
    }
}
