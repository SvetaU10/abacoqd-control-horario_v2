<?php

namespace App\Http\Controllers;

use App\Models\Centro;
use App\Models\Festivo;
use App\Models\SolicitudVacaciones;
use App\Services\CatalogoMunicipios;
use App\Services\CoberturaVacaciones;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GestionController extends Controller
{
    public function index(CatalogoMunicipios $catalogo): View
    {
        $solicitudesVacacionesPendientes = SolicitudVacaciones::query()
            ->where('estado', SolicitudVacaciones::PENDIENTE)
            ->count();

        return view('gestion.index', [
            'solicitudesVacacionesPendientes' => $solicitudesVacacionesPendientes,
            'centros' => Centro::query()->orderBy('name')->get(),
            'estadoCatalogo' => $catalogo->estado(),
            'festivosGestion' => Festivo::query()->with('centro')->orderByDesc('holiday_date')->get(),
        ]);
    }

    public function cobertura(Request $request, CoberturaVacaciones $cobertura): View
    {
        $solicitudes = SolicitudVacaciones::query()->with('autor')
            ->where('estado', SolicitudVacaciones::PENDIENTE)
            ->orderBy('starts_on')
            ->orderBy('id')
            ->paginate(20, ['*'], 'pagina_vacaciones')
            ->withQueryString();
        $centros = Centro::query()->orderBy('name')->get();

        return view('gestion.cobertura-pagina', [
            'solicitudes' => $solicitudes,
            'cobertura' => $solicitudes->total() > 0
                ? $cobertura->paraGestion($centros, $solicitudes, $request->user())
                : null,
        ]);
    }
}
