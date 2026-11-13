<?php

namespace App\Http\Middleware;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class EnsureInstallationAccessIsOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        $limite = trim((string) config('app.access_until', ''));

        if ($limite === '') {
            return $next($request);
        }

        try {
            $fecha = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:sP', $limite);
            $abierto = $fecha !== false
                && $fecha->format('Y-m-d\TH:i:sP') === $limite
                && CarbonImmutable::now()->lt($fecha);
        } catch (InvalidArgumentException) {
            $abierto = false;
        }

        if ($abierto) {
            return $next($request);
        }

        Auth::logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->view('auth.acceso-cerrado', [], 403)
            ->header('Cache-Control', 'no-store, private');
    }
}
