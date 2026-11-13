<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTecnico
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->esTecnico()) {
            return redirect()->route('jornada')->with('aviso', 'Esta página es del administrador técnico.');
        }

        return $next($request);
    }
}
