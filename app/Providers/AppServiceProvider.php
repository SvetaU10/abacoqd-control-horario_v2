<?php

namespace App\Providers;

use App\Models\Incidencia;
use App\Models\SolicitudVacaciones;
use App\Models\Ticket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Carbon::setLocale(config('app.locale'));

        View::composer('layouts.app', function ($view): void {
            $usuario = Auth::user();
            $view->with([
                'ticketsNuevos' => $usuario && $usuario->esTecnico()
                    ? Ticket::query()->where('estado', Ticket::ABIERTO)->count()
                    : 0,
                'ticketsRespuestasNuevas' => $usuario && ! $usuario->esTecnico()
                    ? Ticket::query()->conRespuestaNueva($usuario)->count()
                    : 0,
                'incidenciasNuevas' => ! $usuario ? 0 : ($usuario->esJefa()
                    ? Incidencia::query()->where('estado', '!=', Incidencia::RESUELTA)->whereNull('destinatario_id')->whereNull('vista_at')->count()
                    : Incidencia::query()->where('estado', '!=', Incidencia::RESUELTA)->where('destinatario_id', $usuario->id)->whereNull('vista_at')->count()),
                'vacacionesPendientes' => $usuario && $usuario->esJefa()
                    ? SolicitudVacaciones::query()->where('estado', SolicitudVacaciones::PENDIENTE)->count()
                    : 0,
            ]);
        });
    }
}
