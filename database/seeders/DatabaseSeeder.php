<?php

namespace Database\Seeders;

use App\Models\Centro;
use App\Services\CatalogoMunicipios;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Configuración inicial de AbacoQD. No crea cuentas ni actividad de ejemplo.
     */
    public function run(): void
    {
        $municipio = app(CatalogoMunicipios::class)->resolver('28079');
        Centro::query()->firstOrCreate(['name' => 'Principal'], [
            'municipality' => $municipio['nombre'], 'province' => $municipio['provincia'],
            'community' => $municipio['comunidad'], 'municipio_ine' => $municipio['ine'],
            'province_code' => $municipio['provincia_codigo'], 'comunidad_codigo' => $municipio['comunidad_codigo'],
        ]);
    }
}
