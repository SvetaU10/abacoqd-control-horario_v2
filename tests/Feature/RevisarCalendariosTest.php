<?php

namespace Tests\Feature;

use App\Models\Centro;
use App\Models\ImportacionCalendario;
use App\Models\User;
use App\Services\CalendarioMunicipal;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RevisarCalendariosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-30 12:00:00', 'Europe/Madrid'));
        Cache::flush();
    }

    public function test_exige_identificar_a_una_jefa_activa_sin_inventar_un_autor(): void
    {
        $trabajadora = User::factory()->create();
        $inactiva = User::factory()->jefa()->create(['active' => false]);

        foreach ([null, $trabajadora->id, $inactiva->id] as $autor) {
            $this->artisan('calendario:revisar', $autor === null ? [] : ['--autor' => $autor])
                ->expectsOutput('Indica --autor con el ID de un responsable activo de la revisión.')
                ->assertExitCode(Command::INVALID);
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('importaciones_calendario', 0);
    }

    public function test_revisa_solo_la_ultima_configuracion_del_anio_actual_y_siguiente(): void
    {
        $this->fuentes();
        $jefa = User::factory()->jefa()->create();
        $this->configuracion($jefa->sede, 2025);
        $this->configuracion($jefa->sede, 2026, '00000');
        $ultima = $this->configuracion($jefa->sede, 2026);
        $this->configuracion($jefa->sede, 2027);
        $this->configuracion($jefa->sede, 2028);

        $this->artisan('calendario:revisar', ['--autor' => $jefa->id])
            ->expectsOutputToContain('Madrid · 2026: Cargado.')
            ->expectsOutputToContain('Madrid · 2027: Parcial.')
            ->expectsOutput('Revisados: 2. Omitidos: 0. Errores técnicos: 0.')
            ->assertSuccessful();

        $servicio = app(CalendarioMunicipal::class);
        $this->assertDatabaseCount('importaciones_calendario', 7);
        $this->assertSame('cargado', $servicio->ultima($jefa->sede, 2026)->estado);
        $this->assertSame('parcial', $servicio->ultima($jefa->sede, 2027)->estado);
        $this->assertSame($jefa->id, $servicio->ultima($jefa->sede, 2026)->consultado_por);
        $this->assertSame($jefa->id, $servicio->activo($jefa->sede, 2026)->activado_por);
        $this->assertNotSame($ultima->id, $servicio->activo($jefa->sede, 2026)->id);
        $this->assertNull($servicio->activo($jefa->sede, 2027));
        Http::assertSentCount(4);
    }

    public function test_los_filtros_de_centro_y_anio_no_revisan_otros_calendarios_ni_confirman_candidatos(): void
    {
        $this->fuentes();
        $jefa = User::factory()->jefa()->create();
        $barcelona = Centro::query()->where('name', 'Barcelona')->firstOrFail();
        $this->configuracion($jefa->sede, 2026);
        $this->configuracion($barcelona, 2026, '40194');
        $this->configuracion($barcelona, 2027);

        $this->artisan('calendario:revisar', ['--autor' => $jefa->id, '--centro' => $barcelona->id, '--anio' => 2026])
            ->expectsOutputToContain('Barcelona · 2026: Pendiente de revisión.')
            ->expectsOutput('Revisados: 1. Omitidos: 0. Errores técnicos: 0.')
            ->assertSuccessful();

        $resultado = app(CalendarioMunicipal::class)->ultima($barcelona, 2026);
        $this->assertSame('40194', $resultado->municipio_ine);
        $this->assertSame('pendiente_revision', $resultado->estado);
        $this->assertNull($resultado->clave_activa);
        $this->assertDatabaseCount('importaciones_calendario', 4);
        Http::assertSentCount(2);
    }

    public function test_un_error_tecnico_devuelve_fallo_conserva_lo_activo_y_continua_con_los_demas_centros(): void
    {
        $this->fuentes(['https://festivos.io/v1/2026/municipio/28079.json' => Http::response([], 500)]);
        $jefa = User::factory()->jefa()->create();
        $barcelona = Centro::query()->where('name', 'Barcelona')->firstOrFail();
        $this->calendarioConfirmado($jefa->sede, 2026);
        $activo = app(CalendarioMunicipal::class)->activo($jefa->sede, 2026);
        $this->configuracion($barcelona, 2026, '40194');

        $this->artisan('calendario:revisar', ['--autor' => $jefa->id, '--anio' => 2026])
            ->expectsOutputToContain('HTTP 500')
            ->expectsOutputToContain('Barcelona · 2026: Pendiente de revisión.')
            ->expectsOutput('Revisados: 2. Omitidos: 0. Errores técnicos: 1.')
            ->assertFailed();

        $this->assertSame($activo->id, app(CalendarioMunicipal::class)->activo($jefa->sede, 2026)->id);
        $this->assertSame('error', app(CalendarioMunicipal::class)->ultima($jefa->sede, 2026)->estado);
        $this->assertSame('pendiente_revision', app(CalendarioMunicipal::class)->ultima($barcelona, 2026)->estado);
        Http::assertSentCount(3);
    }

    public function test_omite_territorios_modificados_y_municipios_invalidos_sin_recuperar_configuraciones_antiguas(): void
    {
        $this->fuentes();
        $jefa = User::factory()->jefa()->create();
        $barcelona = Centro::query()->where('name', 'Barcelona')->firstOrFail();
        $sevilla = Centro::query()->where('name', 'Sevilla')->firstOrFail();
        $this->configuracion($jefa->sede, 2026);
        $this->configuracion($jefa->sede, 2026, '28079', 'configuracion_modificada');
        $this->configuracion($barcelona, 2026, 'mal');
        $this->configuracion($sevilla, 2026, '00000');

        $this->artisan('calendario:revisar', ['--autor' => $jefa->id])
            ->expectsOutputToContain('Ha cambiado el territorio del centro')
            ->expectsOutputToContain('La configuración no tiene un municipio válido')
            ->expectsOutputToContain('Ese código de municipio no está en el catálogo local')
            ->expectsOutput('Revisados: 0. Omitidos: 3. Errores técnicos: 0.')
            ->assertSuccessful();

        $this->assertDatabaseCount('importaciones_calendario', 4);
        Http::assertNothingSent();
    }

    public function test_valida_los_filtros_antes_de_consultar_y_explica_cuando_no_hay_calendarios_configurados(): void
    {
        $jefa = User::factory()->jefa()->create();
        foreach ([['--anio' => '2026x'], ['--anio' => 2101], ['--centro' => 'inexistente'], ['--centro' => 99999]] as $opciones) {
            $this->artisan('calendario:revisar', ['--autor' => $jefa->id] + $opciones)
                ->assertExitCode(Command::INVALID);
        }
        $this->artisan('calendario:revisar', ['--autor' => $jefa->id])
            ->expectsOutputToContain('No hay calendarios configurados')
            ->expectsOutput('Revisados: 0. Omitidos: 0. Errores técnicos: 0.')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertDatabaseCount('importaciones_calendario', 0);
    }

    private function configuracion(Centro $centro, int $anio, string $ine = '28079', string $estado = 'no_disponible'): ImportacionCalendario
    {
        return ImportacionCalendario::query()->create([
            'centro_id' => $centro->id,
            'anio' => $anio,
            'municipio_ine' => $ine,
            'municipio' => $ine === '40194' ? 'Segovia' : 'Madrid',
            'provincia' => $ine === '40194' ? 'Segovia' : 'Madrid',
            'comunidad' => $ine === '40194' ? 'Castilla y León' : 'Comunidad de Madrid',
            'comunidad_codigo' => $ine === '40194' ? 'ES-CL' : 'ES-MD',
            'estado' => $estado,
            'mensaje' => 'Consulta anterior',
            'festivos' => [],
            'fuentes' => [],
            'consultado_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function fixtureCalendario(string $nombre): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/Calendario/'.$nombre.'.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $respuestas */
    private function fuentes(array $respuestas = []): void
    {
        Http::fake($respuestas + [
            CalendarioMunicipal::CATALOGO => Http::response($this->fixtureCalendario('municipios')),
            'https://festivos.io/v1/2026/municipio/28079.json' => Http::response($this->fixtureCalendario('madrid-2026')),
            'https://festivos.io/v1/2027/municipio/28079.json' => Http::response($this->fixtureCalendario('madrid-2027')),
            'https://festivos.io/v1/2026/municipio/40194.json' => Http::response($this->fixtureCalendario('segovia-2026')),
            CalendarioMunicipal::CAM_REGIONALES => Http::response($this->fixtureCalendario('cam-regionales-2026')),
            CalendarioMunicipal::CAM_LOCALES => Http::response($this->fixtureCalendario('cam-locales-madrid-2026')),
            'https://www.boe.es/diario_boe/xml.php?id=BOE-A-2025-21667' => Http::response('', 503),
        ]);
    }
}
