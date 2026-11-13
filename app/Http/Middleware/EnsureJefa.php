<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureJefa
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->esJefa()) {
            return redirect()->route($request->user()->paginaInicio())->with('aviso', 'Esta página es de el responsable del equipo.');
        }

        return $next($request);
    }
}
