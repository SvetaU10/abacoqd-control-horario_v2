<?php

namespace Database\Seeders;

use App\Services\PlantillaDemo;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call(CentrosDemoSeeder::class);
        app(PlantillaDemo::class)->aplicar();
    }
}
