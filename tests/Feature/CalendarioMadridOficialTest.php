<?php

namespace Tests\Feature;

use App\Models\Centro;
use App\Models\Festivo;
use App\Models\ImportacionCalendario;
use App\Models\User;
use App\Models\VigenciaCalendario;
use App\Services\CalendarioMadridOficial;
use App\Services\CalendarioMunicipal;
use App\Services\NagerFestivos;
use App\Services\VigenciasCalendario;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CalendarioMadridOficialTest extends TestCase
{
    use RefreshDatabase;

    private Centro $centro;

    private User $autor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-06 12:00:00', 'Europe/Madrid'));
        $this->centro = Centro::query()->updateOrCreate(['name' => 'Madrid'], [
            'municipality' => 'Madrid', 'community' => 'Comunidad de Madrid',
            'municipio_ine' => '28079', 'comunidad_codigo' => 'ES-MD', 'province' => 'Madrid', 'province_code' => '28',
        ]);
        $this->autor = User::factory()->jefa()->create(['centro_id' => $this->centro->id]);
    }

    public function test_prepara_sin_red_cobertura_real_desde_el_inicio_y_todo_2027_con_procedencia(): void
    {
        $this->artisan('abaco:preparar-calendario-madrid', $this->opciones())->assertSuccessful();

        $this->assertDatabaseCount('importaciones_calendario', 2);
        $this->assertDatabaseCount('vigencias_calendario', 2);
        $this->assertDatabaseCount('jornadas', 0);
        $this->assertDatabaseCount('festivos', 0);
        $servicio = app(NagerFestivos::class);
        $this->assertSame(['2026-11-09', '2026-12-07', '2026-12-08', '2026-12-25'],
            $servicio->delAnio($this->centro, 2026)->map(fn ($dia) => $dia->holiday_date->toDateString())->all());
        $this->assertFalse($servicio->coberturaConfirmada($this->centro, 2026, Carbon::parse('2026-11-05')));
        $this->assertTrue($servicio->coberturaConfirmada($this->centro, 2026, Carbon::parse('2026-11-06')));
        $this->assertFalse($servicio->coberturaConfirmada($this->centro, 2026));
        $this->assertTrue($servicio->coberturaConfirmada($this->centro, 2027));
        $this->assertSame([
            '2027-01-01', '2027-01-06', '2027-03-19', '2027-03-25', '2027-03-26', '2027-05-01', '2027-05-15',
            '2027-08-16', '2027-10-12', '2027-11-01', '2027-11-09', '2027-12-06', '2027-12-08', '2027-12-25',
        ], $servicio->delAnio($this->centro, 2027)->map(fn ($dia) => $dia->holiday_date->toDateString())->all());
        foreach (ImportacionCalendario::all() as $importacion) {
            $this->assertCount(14, $importacion->festivos);
            $this->assertCount(2, array_filter($importacion->festivos, fn ($dia) => $dia['ambito'] === 'pueblo'));
            $this->assertSame('28079', $importacion->municipio_ine);
            $this->assertSame($this->autor->id, $importacion->activado_por);
            $this->assertSame('2026-11-06', $importacion->fuentes[0]['verificado_el']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $importacion->fuentes[0]['sha256_snapshot']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $importacion->hash);
            foreach ($importacion->festivos as $dia) {
                $this->assertNotEmpty($dia['fuente']);
                $this->assertStringStartsWith('https://', $dia['url']);
            }
        }
        Http::assertNothingSent();
    }

    public function test_repetir_es_idempotente_y_no_reabre_la_vigencia_historica(): void
    {
        $primera = $this->preparar();
        Carbon::setTestNow('2026-11-07 10:00:00');
        $segunda = $this->preparar();
        foreach ($primera as $anio => $importacion) {
            $this->assertSame($importacion->id, $segunda[$anio]->id);
            $this->assertTrue($importacion->updated_at->eq($segunda[$anio]->updated_at));
        }
        $this->assertDatabaseCount('importaciones_calendario', 2);
        $this->assertDatabaseCount('vigencias_calendario', 2);
        $this->artisan('abaco:preparar-calendario-madrid', [...$this->opciones(), '--desde' => '2026-01-01'])
            ->assertExitCode(Command::INVALID);
        $this->assertSame('2026-11-06', VigenciaCalendario::query()->where('anio', 2026)->first()->desde->toDateString());
        Http::assertNothingSent();
    }

    public function test_exige_fecha_autor_activo_autorizado_centro_y_rango_sin_crear_datos(): void
    {
        $trabajador = User::factory()->create(['centro_id' => $this->centro->id]);
        $inactivo = User::factory()->jefa()->create(['centro_id' => $this->centro->id, 'active' => false]);
        foreach ([
            ['--desde' => null], ['--desde' => '2026-02-30'], ['--desde' => '2025-12-01'],
            ['--desde' => '2027-02-01', '--hasta-anio' => '2026'], ['--hasta-anio' => '2028'],
            ['--autor' => null], ['--autor' => $trabajador->id], ['--autor' => $inactivo->id],
            ['--centro' => null], ['--centro' => '999999'],
        ] as $cambio) {
            $this->artisan('abaco:preparar-calendario-madrid', [...$this->opciones(), ...$cambio])->assertExitCode(Command::INVALID);
        }
        $this->assertDatabaseCount('importaciones_calendario', 0);
        $this->assertDatabaseCount('vigencias_calendario', 0);
        Http::assertNothingSent();
    }

    public function test_el_tecnico_puede_preparar_2027_sin_inventar_calendario_2026(): void
    {
        $tecnico = User::factory()->tecnico()->create(['centro_id' => $this->centro->id]);
        $this->artisan('abaco:preparar-calendario-madrid', [...$this->opciones(), '--autor' => $tecnico->id, '--desde' => '2027-01-01'])
            ->assertSuccessful();
        $this->assertDatabaseCount('importaciones_calendario', 1);
        $vigencia = VigenciaCalendario::query()->sole();
        $this->assertSame(2027, $vigencia->anio);
        $this->assertSame('2027-01-01', $vigencia->desde->toDateString());
        $this->assertSame('2027-12-31', $vigencia->hasta->toDateString());
    }

    public function test_no_traslada_los_festivos_de_la_capital_a_otro_municipio(): void
    {
        $this->centro->update(['municipio_ine' => '28065', 'municipality' => 'Getafe']);
        $this->artisan('abaco:preparar-calendario-madrid', $this->opciones())->assertExitCode(Command::INVALID);
        $this->assertDatabaseCount('importaciones_calendario', 0);
        $this->assertSame('28065', $this->centro->fresh()->municipio_ine);
    }

    public function test_un_calendario_diferente_en_2027_aborta_todo_sin_insertar_2026(): void
    {
        $this->calendarioConfirmado($this->centro, 2027);
        $existente = ImportacionCalendario::first();
        $this->artisan('abaco:preparar-calendario-madrid', $this->opciones())->assertExitCode(Command::INVALID);
        $this->assertDatabaseCount('importaciones_calendario', 1);
        $this->assertDatabaseCount('vigencias_calendario', 0);
        $this->assertSame($existente->hash, $existente->fresh()->hash);
        $this->assertSame($existente->clave_activa, $existente->fresh()->clave_activa);
    }

    public function test_no_sustituye_festivos_personalizados_y_revierte_los_dos_anios_juntos(): void
    {
        $manual = Festivo::create([
            'centro_id' => $this->centro->id, 'municipality' => 'Madrid', 'community' => 'Madrid',
            'holiday_date' => '2027-12-24', 'name' => 'Cierre acordado', 'ambito' => 'pueblo',
        ]);
        $this->artisan('abaco:preparar-calendario-madrid', $this->opciones())->assertExitCode(Command::INVALID);
        $this->assertDatabaseCount('importaciones_calendario', 0);
        $this->assertDatabaseCount('vigencias_calendario', 0);
        $this->assertSame('Cierre acordado', $manual->fresh()->name);
    }

    public function test_no_confunde_un_festivo_de_otro_municipio_con_un_ajuste_de_madrid(): void
    {
        Festivo::create(['municipality' => 'Getafe', 'holiday_date' => '2027-06-01', 'name' => 'Local de otro municipio', 'ambito' => 'pueblo']);
        $this->preparar();
        $this->assertDatabaseCount('importaciones_calendario', 2);
        $this->assertDatabaseCount('festivos', 1);
    }

    public function test_rechaza_copias_incompletas_duplicadas_de_otro_territorio_o_con_huella_alterada(): void
    {
        $original = json_decode(file_get_contents(resource_path('data/calendarios/madrid-28079-2027.json')), true, flags: JSON_THROW_ON_ERROR);
        $variantes = [];
        $parcial = $original;
        array_pop($parcial['festivos']);
        $variantes[] = $parcial;
        $duplicado = $original;
        $duplicado['festivos'][1] = $duplicado['festivos'][0];
        $variantes[] = $duplicado;
        $otro = $original;
        $otro['municipio_ine'] = '28065';
        $variantes[] = $otro;
        $alterado = $original;
        $alterado['festivos'][0]['date'] = '2027-01-02';
        $variantes[] = $alterado;
        foreach ($variantes as $datos) {
            $servicio = Mockery::mock(CalendarioMadridOficial::class, [app(VigenciasCalendario::class)])->makePartial()->shouldAllowMockingProtectedMethods();
            $servicio->shouldReceive('leerSnapshot')->with(2027)->andReturn($datos);
            try {
                $servicio->preparar($this->centro, $this->autor, '2027-01-01');
                $this->fail('Una copia alterada no puede activarse.');
            } catch (RuntimeException $error) {
                $this->assertNotEmpty($error->getMessage());
            }
        }
        $this->assertDatabaseCount('importaciones_calendario', 0);
        $this->assertDatabaseCount('vigencias_calendario', 0);
    }

    public function test_la_revision_automatica_parcial_conserva_el_oficial_2027_completo(): void
    {
        $oficial = $this->preparar()[2027];
        Http::fake(['https://festivos.io/v1/2027/municipio/28079.json' => Http::response($this->fixture('madrid-2027'))]);
        $this->artisan('calendario:revisar', ['--autor' => $this->autor->id, '--anio' => 2027])->assertSuccessful();
        $this->assertSame($oficial->id, app(CalendarioMunicipal::class)->activo($this->centro, 2027)->id);
        $this->assertSame('parcial', app(CalendarioMunicipal::class)->ultima($this->centro, 2027)->estado);
        $this->assertTrue(app(NagerFestivos::class)->coberturaConfirmada($this->centro, 2027));
        $this->assertTrue(app(NagerFestivos::class)->es($this->centro, Carbon::parse('2027-11-09')));
        $this->assertDatabaseCount('vigencias_calendario', 2);
        Http::assertSentCount(1);
    }

    public function test_un_proveedor_coincidente_no_sustituye_la_procedencia_oficial_local(): void
    {
        $oficial = $this->preparar()[2026];
        Http::fake([
            'https://festivos.io/v1/2026/municipio/28079.json' => Http::response($this->fixture('madrid-2026')),
            CalendarioMunicipal::CAM_REGIONALES => Http::response($this->fixture('cam-regionales-2026')),
            CalendarioMunicipal::CAM_LOCALES => Http::response($this->fixture('cam-locales-madrid-2026')),
        ]);
        $resultado = app(CalendarioMunicipal::class)->cargar($this->centro, 2026, '28079', $this->autor, desde: '2026-11-06');
        $this->assertSame('sin_cambios', $resultado->estado);
        $this->assertSame($oficial->id, app(CalendarioMunicipal::class)->activo($this->centro, 2026)->id);
        $this->assertSame($oficial->fuentes, $oficial->fresh()->fuentes);
        $this->assertDatabaseCount('vigencias_calendario', 2);
    }

    public function test_no_permite_que_un_candidato_completo_distinto_sobrescriba_la_copia_oficial(): void
    {
        $oficial = $this->preparar()[2027];
        $dias = $oficial->festivos;
        $dias[0]['date'] = '2027-01-02';
        $candidato = ImportacionCalendario::create([
            ...$oficial->only(['centro_id', 'anio', 'municipio_ine', 'municipio', 'provincia', 'comunidad', 'comunidad_codigo', 'vigente_desde']),
            'festivos' => $dias, 'fuentes' => [], 'hash' => hash('sha256', 'candidato distinto'),
            'estado' => 'pendiente_revision', 'mensaje' => 'Candidato de proveedor', 'consultado_at' => now(),
        ]);
        $resultado = app(CalendarioMunicipal::class)->confirmar($candidato, $this->autor);
        $this->assertSame('conflicto', $resultado->estado);
        $this->assertSame($oficial->id, app(CalendarioMunicipal::class)->activo($this->centro, 2027)->id);
        $this->assertDatabaseCount('vigencias_calendario', 2);
    }

    public function test_la_activacion_directa_tambien_rechaza_un_snapshot_recortado(): void
    {
        $oficial = $this->preparar()[2027];
        $oficial->update(['festivos' => array_slice($oficial->festivos, 0, 12)]);
        $this->expectException(InvalidArgumentException::class);
        app(VigenciasCalendario::class)->activar($oficial);
    }

    public function test_la_proteccion_oficial_incluye_el_ultimo_dia_del_anio(): void
    {
        $oficial = $this->preparar()[2027];
        $dias = $oficial->festivos;
        $dias[0]['date'] = '2027-01-02';
        $candidato = ImportacionCalendario::create([
            ...$oficial->only(['centro_id', 'anio', 'municipio_ine', 'municipio', 'provincia', 'comunidad', 'comunidad_codigo']),
            'festivos' => $dias, 'fuentes' => [], 'hash' => hash('sha256', 'cambio a final de anio'),
            'estado' => 'pendiente_revision', 'mensaje' => 'Candidato distinto', 'consultado_at' => now(),
            'vigente_desde' => '2027-12-31',
        ]);
        $resultado = app(CalendarioMunicipal::class)->confirmar($candidato, $this->autor);
        $this->assertSame('conflicto', $resultado->estado);
        $this->assertSame($oficial->id, app(VigenciasCalendario::class)->paraFecha($this->centro, Carbon::parse('2027-12-31'))->id);
    }

    /** @return array<int,ImportacionCalendario> */
    private function preparar(): array
    {
        return app(CalendarioMadridOficial::class)->preparar($this->centro, $this->autor, '2026-11-06');
    }

    /** @return array<string,mixed> */
    private function opciones(): array
    {
        return ['--centro' => $this->centro->id, '--autor' => $this->autor->id, '--desde' => '2026-11-06', '--hasta-anio' => 2027];
    }

    /** @return array<string,mixed> */
    private function fixture(string $nombre): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/Calendario/'.$nombre.'.json')), true, flags: JSON_THROW_ON_ERROR);
    }
}
