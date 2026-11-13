<?php

namespace Tests;

use App\Models\Centro;
use App\Models\Festivo;
use App\Models\ImportacionCalendario;
use Database\Seeders\CentrosDemoSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected $seed = true;

    protected $seeder = CentrosDemoSeeder::class;

    /** @var list<array<string, mixed>> */
    protected array $festivosNager = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * @param  list<array<string, mixed>>  $dias
     */
    protected function festivosPublicos(array $dias): void
    {
        $this->festivosNager = $dias;
        foreach ($dias as $dia) {
            if (($dia['types'] ?? []) === ['Observance']) {
                continue;
            }

            $nombresSede = [
                'ES-MD' => 'Madrid',
                'ES-CT' => 'Barcelona',
                'ES-AN' => 'Sevilla',
                'ES-GETAFE' => 'Getafe',
            ];
            $centro = ($dia['global'] ?? false)
                ? null
                : Centro::query()->where('name', $nombresSede[$dia['counties'][0] ?? ''] ?? ($dia['counties'][0] ?? ''))->first();

            Festivo::create([
                'holiday_date' => $dia['date'],
                'municipality' => $centro?->municipality ?? '',
                'name' => $dia['localName'],
                'ambito' => ($dia['global'] ?? false) ? 'estado' : 'comunidad',
                'community' => $centro?->community,
                'centro_id' => ($dia['global'] ?? false) ? null : $centro?->id,
            ]);
        }
        cache()->flush();
    }

    protected function calendarioConfirmado(Centro $centro, int $anio): void
    {
        ImportacionCalendario::query()->firstOrCreate(
            ['clave_activa' => $centro->id.':'.$anio],
            [
                'centro_id' => $centro->id,
                'anio' => $anio,
                'municipio_ine' => $centro->municipio_ine ?? '28079',
                'municipio' => $centro->municipality,
                'provincia' => $centro->province ?? 'Madrid',
                'comunidad' => $centro->community,
                'comunidad_codigo' => $centro->comunidad_codigo ?? 'ES-MD',
                'estado' => 'cargado',
                'mensaje' => 'Calendario de prueba confirmado',
                'festivos' => [],
                'fuentes' => [],
                'hash' => hash('sha256', 'fixture-'.$centro->id.'-'.$anio),
                'consultado_at' => now(),
                'activado_at' => now(),
            ],
        );
    }
}
