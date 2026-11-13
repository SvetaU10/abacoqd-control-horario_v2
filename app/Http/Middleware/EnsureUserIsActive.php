<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            // Una baja con fecha ya cumplida cierra la cuenta para el resto de la aplicación.
            User::cerrarAccesosVencidos(now());
        }

        if ($user && ! $user->tieneAccesoEl(now())) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('aviso', 'Tu acceso está cerrado. Tus registros se conservan.');
        }

        return $next($request);
    }
}
