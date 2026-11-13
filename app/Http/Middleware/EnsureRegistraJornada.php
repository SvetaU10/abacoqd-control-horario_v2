<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRegistraJornada
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user?->registraJornada()) {
            if ($user?->esTecnico() && $request->isMethod('GET') && $request->routeIs('jornada')) {
                return redirect()->route('tecnico.index');
            }

            abort(403, 'Esta cuenta es exclusivamente técnica.');
        }

        $persona = $request->route('user');
        if ($persona instanceof User) {
            abort_unless($persona->registraJornada(), 404);
        }

        return $next($request);
    }
}
