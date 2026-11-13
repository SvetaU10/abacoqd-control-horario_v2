<?php

namespace Database\Seeders;

use App\Models\Centro;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

class CentrosDemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment('local', 'testing') || (! app()->runningUnitTests() && ! config('app.demo'))) {
            throw new RuntimeException('Las sedes de demostración sólo se pueden cargar en un entorno local de demo explícito.');
        }
        $sedes = [
            ['Madrid', 'Madrid'], ['Barcelona', 'Cataluña'], ['Sevilla', 'Andalucía'],
            ['Valencia', 'Comunitat Valenciana'], ['Bilbao', 'País Vasco'], ['A Coruña', 'Galicia'],
            ['Zaragoza', 'Aragón'], ['Palma', 'Islas Baleares'], ['Murcia', 'Región de Murcia'],
            ['Valladolid', 'Castilla y León'], ['Toledo', 'Castilla-La Mancha'], ['Mérida', 'Extremadura'],
            ['Oviedo', 'Asturias'], ['Santander', 'Cantabria'], ['Logroño', 'La Rioja'],
            ['Pamplona', 'Navarra'], ['Santa Cruz de Tenerife', 'Canarias'], ['Ceuta', 'Ceuta'], ['Melilla', 'Melilla'],
        ];
        foreach ($sedes as [$municipio, $comunidad]) {
            Centro::query()->firstOrCreate(['name' => Str::ascii($municipio)], ['municipality' => $municipio, 'community' => $comunidad]);
        }
    }
}
