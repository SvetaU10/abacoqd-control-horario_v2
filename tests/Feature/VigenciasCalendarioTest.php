<?php

namespace Tests\Feature;

use App\Models\Ausencia;
use App\Models\Centro;
use App\Models\Festivo;
use App\Models\Horario;
use App\Models\ImportacionCalendario;
use App\Models\Jornada;
use App\Models\Tramo;
use App\Models\User;
use App\Models\VigenciaCalendario;
use App\Services\AjustarSaldoVacaciones;
use App\Services\CupoVacaciones;
use App\Services\JornadaService;
use App\Services\NagerFestivos;
use App\Services\VigenciasCalendario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class VigenciasCalendarioTest extends TestCase
{
    use RefreshDatabase;

    public function test_hoy_del_jefe_no_pide_cargar_el_anio_completo_si_su_vigencia_inicial_cubre_hoy(): void
    {
        $this->travelTo(Carbon::parse('2026-11-06 08:00:00', 'Europe/Madrid'));
        $centro = Centro::query()->where('name', 'Madrid')->firstOrFail();
        $jefe = User::factory()->create(['role' => 'jefe', 'centro_id' => $centro->id, 'starts_on' => '2026-11-06']);
        Horario::create(['user_id' => $jefe->id, 'morning_start' => '09:00', 'morning_end' => '14:00', 'afternoon_start' => '15:00', 'afternoon_end' => '18:00', 'effective_from' => '2026-11-06']);
        app(VigenciasCalendario::class)->activar($this->importacion($centro, 'Madrid', '2026-11-06', []));

        $this->assertFalse(app(NagerFestivos::class)->coberturaPersonaConfirmada($jefe, 2026));
        $this->assertTrue(app(NagerFestivos::class)->coberturaConfirmada($centro, 2026, now()));
        $this->actingAs($jefe)->get(route('jornada'))
            ->assertOk()
            ->assertDontSee('El calendario de 2026 no está cargado.')
            ->assertViewHas('banner', null)
            ->assertViewHas('expectativa', fn (array $expectativa): bool => $expectativa['estado'] === 'laborable');

        $this->assertDatabaseCount('vigencias_calendario', 1);
        $this->assertSame('2026-11-06', VigenciaCalendario::query()->sole()->desde->toDateString());
        $this->assertNull(app(VigenciasCalendario::class)->paraFecha($centro, Carbon::parse('2026-11-05')));
    }

    public function test_hoy_del_jefe_sigue_avisando_si_solo_hay_una_vigencia_futura(): void
    {
        $this->travelTo(Carbon::parse('2026-11-06 08:00:00', 'Europe/Madrid'));
        $centro = Centro::query()->where('name', 'Madrid')->firstOrFail();
        $jefe = User::factory()->create(['role' => 'jefe', 'centro_id' => $centro->id, 'starts_on' => '2026-11-06']);
        Horario::create(['user_id' => $jefe->id, 'morning_start' => '09:00', 'morning_end' => '14:00', 'afternoon_start' => '15:00', 'afternoon_end' => '18:00', 'effective_from' => '2026-11-06']);
        app(VigenciasCalendario::class)->activar($this->importacion($centro, 'Madrid', '2026-11-07', []));

        $this->assertFalse(app(NagerFestivos::class)->coberturaConfirmada($centro, 2026, now()));
        $this->actingAs($jefe)->get(route('jornada'))
            ->assertOk()
            ->assertSee('Cargar calendario')
            ->assertViewHas('banner', fn (array $banner): bool => $banner['accion'] === 'calendario')
            ->assertViewHas('expectativa', fn (array $expectativa): bool => $expectativa['motivo'] === 'calendario');
    }

    public function test_cambiar_madrid_por_segovia_desde_febrero_conserva_enero_vacaciones_y_fichajes(): void
    {
        $this->travelTo(Carbon::parse('2026-10-30 12:00:00', 'Europe/Madrid'));
        $centro = Centro::query()->where('name', 'Madrid')->firstOrFail();
        $ana = User::factory()->create(['centro_id' => $centro->id]);
        $responsable = User::factory()->jefa()->create();
        app(AjustarSaldoVacaciones::class)->ajustar($responsable, $ana, 2026, 22, 0, 0, 'Saldo confirmado en prueba', (string) Str::uuid());
        $vacacion = Ausencia::create(['user_id' => $ana->id, 'absence_date' => '2026-01-20', 'tipo' => 'vacaciones']);
        $jornada = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-01-19']);
        $trabajo = Tramo::create(['jornada_id' => $jornada->id, 'tipo' => Tramo::TRABAJO, 'started_at' => '2026-01-19 09:00:00', 'ended_at' => '2026-01-19 18:00:00']);
        $antesVacacion = $vacacion->fresh()->getRawOriginal();
        $antesTrabajo = $trabajo->fresh()->getRawOriginal();
        $madrid = $this->importacion($centro, 'Madrid', '2026-01-01', [
            ['date' => '2026-01-06', 'name' => 'Epifanía', 'ambito' => 'estado'],
            ['date' => '2026-05-02', 'name' => 'Día de Madrid', 'ambito' => 'comunidad'],
            ['date' => '2026-11-09', 'name' => 'Almudena', 'ambito' => 'pueblo'],
            ['date' => '2026-12-07', 'name' => 'Calendario de Madrid', 'ambito' => 'estado'],
        ]);
        $servicio = app(VigenciasCalendario::class);
        $servicio->activar($madrid);
        Festivo::create(['holiday_date' => '2026-01-05', 'name' => 'Festivo manual de enero', 'ambito' => 'pueblo', 'municipality' => 'Madrid', 'centro_id' => $centro->id]);
        Festivo::create(['holiday_date' => '2026-11-09', 'name' => 'Almudena manual', 'ambito' => 'pueblo', 'municipality' => 'Madrid', 'centro_id' => $centro->id]);
        $segovia = $this->importacion($centro, 'Segovia', '2026-02-01', [
            ['date' => '2026-01-06', 'name' => 'Epifanía', 'ambito' => 'estado'],
            ['date' => '2026-04-23', 'name' => 'Castilla y León', 'ambito' => 'comunidad'],
            ['date' => '2026-06-29', 'name' => 'San Pedro', 'ambito' => 'pueblo'],
            ['date' => '2026-12-06', 'name' => 'Calendario de Segovia', 'ambito' => 'estado'],
        ]);

        $servicio->activar($segovia);
        $centro->update(['municipality' => 'Segovia', 'community' => 'Castilla y León']);

        $festivos = app(NagerFestivos::class);
        $fechas = $festivos->delAnio($centro, 2026)->pluck('holiday_date')->map->toDateString()->all();
        $this->assertSame(['2026-01-05', '2026-01-06', '2026-04-23', '2026-06-29', '2026-12-06'], $fechas);
        $this->assertSame($madrid->id, $servicio->paraFecha($centro, Carbon::parse('2026-01-31'))->id);
        $this->assertSame($segovia->id, $servicio->paraFecha($centro, Carbon::parse('2026-02-01'))->id);
        $this->assertTrue($festivos->coberturaConfirmada($centro, 2026));
        $despuesVacacion = $vacacion->fresh()->getRawOriginal();
        $despuesTrabajo = $trabajo->fresh()->getRawOriginal();
        ksort($antesVacacion);
        ksort($despuesVacacion);
        ksort($antesTrabajo);
        ksort($despuesTrabajo);
        $this->assertSame($antesVacacion, $despuesVacacion);
        $this->assertSame($antesTrabajo, $despuesTrabajo);
        $this->assertSame(540, app(JornadaService::class)->minutosRegistrados($ana, $jornada->fresh('tramos')));
        $this->assertSame(21, app(CupoVacaciones::class)->restantes($ana, 2026));
        $this->assertSame('ausencia', app(JornadaService::class)->expectativaDe($ana, Carbon::parse('2026-01-20'))['motivo']);
        $original = VigenciaCalendario::query()->where('importacion_id', $madrid->id)->whereNotNull('cancelado_at')->firstOrFail();
        $this->assertSame('2026-01-01', $original->desde->toDateString());
        $this->assertSame('2026-12-31', $original->hasta->toDateString());
        $this->assertSame(['2026-01-31', '2026-12-31'], $servicio->delAnio($centro, 2026)->pluck('hasta')->map->toDateString()->all());
    }

    public function test_una_aplicacion_futura_no_cambia_el_calendario_anterior_a_su_fecha(): void
    {
        $this->travelTo(Carbon::parse('2026-01-15 12:00:00', 'Europe/Madrid'));
        $centro = Centro::query()->where('name', 'Madrid')->firstOrFail();
        $servicio = app(VigenciasCalendario::class);
        $madrid = $this->importacion($centro, 'Madrid', '2026-01-01', []);
        $segovia = $this->importacion($centro, 'Segovia', '2026-06-01', []);
        $servicio->activar($madrid);

        $servicio->activar($segovia);

        $this->assertSame($madrid->id, $servicio->paraFecha($centro, now())->id);
        $this->assertSame($madrid->id, $servicio->paraFecha($centro, Carbon::parse('2026-05-31'))->id);
        $this->assertSame($segovia->id, $servicio->paraFecha($centro, Carbon::parse('2026-06-01'))->id);
        $this->assertTrue(app(NagerFestivos::class)->coberturaConfirmada($centro, 2026));
    }

    public function test_una_sustitucion_retroactiva_reemplaza_todo_el_futuro_desde_la_fecha_elegida(): void
    {
        $centro = Centro::query()->where('name', 'Madrid')->firstOrFail();
        $servicio = app(VigenciasCalendario::class);
        $madrid = $this->importacion($centro, 'Madrid', '2026-01-01', []);
        $segovia = $this->importacion($centro, 'Segovia', '2026-06-01', []);
        $valencia = $this->importacion($centro, 'València', '2026-03-01', []);
        $servicio->activar($madrid);
        $servicio->activar($segovia);

        $servicio->activar($valencia);

        $this->assertSame($madrid->id, $servicio->paraFecha($centro, Carbon::parse('2026-02-28'))->id);
        $this->assertSame($valencia->id, $servicio->paraFecha($centro, Carbon::parse('2026-03-01'))->id);
        $this->assertSame($valencia->id, $servicio->paraFecha($centro, Carbon::parse('2026-12-31'))->id);
        $this->assertCount(2, $servicio->delAnio($centro, 2026));
        $this->assertTrue(VigenciaCalendario::query()->where('importacion_id', $segovia->id)->whereNotNull('cancelado_at')->exists());
        $this->assertModelExists($segovia);
    }

    public function test_repetir_la_misma_importacion_y_fecha_no_duplica_tramos_ni_auditoria(): void
    {
        $centro = Centro::query()->where('name', 'Madrid')->firstOrFail();
        $servicio = app(VigenciasCalendario::class);
        $madrid = $this->importacion($centro, 'Madrid', '2026-01-01', []);
        $segovia = $this->importacion($centro, 'Segovia', '2026-02-01', []);
        $servicio->activar($madrid);
        $servicio->activar($segovia);
        $filas = VigenciaCalendario::query()->count();

        $servicio->activar($segovia);

        $this->assertSame($filas, VigenciaCalendario::query()->count());
        $this->assertCount(2, $servicio->delAnio($centro, 2026));
    }

    public function test_sustituir_el_anio_entero_conserva_los_tramos_anteriores_cancelados(): void
    {
        $centro = Centro::query()->where('name', 'Madrid')->firstOrFail();
        $servicio = app(VigenciasCalendario::class);
        $madrid = $this->importacion($centro, 'Madrid', '2026-01-01', []);
        $segovia = $this->importacion($centro, 'Segovia', '2026-02-01', []);
        $valencia = $this->importacion($centro, 'València', '2026-01-01', []);
        $servicio->activar($madrid);
        $servicio->activar($segovia);

        $servicio->activar($valencia);

        $this->assertCount(1, $servicio->delAnio($centro, 2026));
        $this->assertSame($valencia->id, $servicio->paraFecha($centro, Carbon::parse('2026-01-01'))->id);
        $this->assertSame($valencia->id, $servicio->paraFecha($centro, Carbon::parse('2026-12-31'))->id);
        $this->assertSame(3, VigenciaCalendario::query()->whereNotNull('cancelado_at')->count());
    }

    public function test_invalidar_desde_hoy_conserva_el_pasado_y_los_huecos_no_reactivan_legacy(): void
    {
        $this->travelTo(Carbon::parse('2026-03-01 12:00:00', 'Europe/Madrid'));
        $centro = Centro::query()->where('name', 'Madrid')->firstOrFail();
        $servicio = app(VigenciasCalendario::class);
        $anterior = $this->importacion($centro, 'Madrid', '2025-01-01', [], true);
        $actual = $this->importacion($centro, 'Madrid', '2026-01-01', [], true);
        $futuro = $this->importacion($centro, 'Madrid', '2027-01-01', [], true);

        $servicio->invalidar($centro, now());

        $this->assertSame($anterior->id, $servicio->paraFecha($centro, Carbon::parse('2025-12-31'))->id);
        $this->assertSame($actual->id, $servicio->paraFecha($centro, Carbon::parse('2026-02-28'))->id);
        $this->assertNull($servicio->paraFecha($centro, Carbon::parse('2026-03-01')));
        $this->assertNull($servicio->paraFecha($centro, Carbon::parse('2027-01-01')));
        $this->assertTrue(app(NagerFestivos::class)->coberturaConfirmada($centro, 2026, Carbon::parse('2026-02-28')));
        $this->assertFalse(app(NagerFestivos::class)->coberturaConfirmada($centro, 2026));
        $this->assertFalse(app(NagerFestivos::class)->coberturaConfirmada($centro, 2027));
        $this->assertNotNull($actual->fresh()->clave_activa);
        $this->assertNotNull($futuro->fresh()->clave_activa);
        $this->assertCount(0, $servicio->delAnio($centro, 2027));
    }

    public function test_las_filas_legacy_se_leen_sin_escribir_y_se_materializan_antes_de_cambiar_municipio(): void
    {
        $centro = Centro::query()->where('name', 'Madrid')->firstOrFail();
        $madrid = $this->importacion($centro, 'Madrid', '2026-01-01', [], true);
        $servicio = app(VigenciasCalendario::class);

        $this->assertSame($madrid->id, $servicio->paraFecha($centro, Carbon::parse('2026-01-31'))->id);
        $this->assertDatabaseCount('vigencias_calendario', 0);
        $servicio->asegurarLegado($centro, 2026);
        $madrid->update(['clave_activa' => null]);
        $segovia = $this->importacion($centro, 'Segovia', '2026-02-01', []);
        $servicio->activar($segovia);

        $this->assertSame($madrid->id, $servicio->paraFecha($centro, Carbon::parse('2026-01-31'))->id);
        $this->assertSame($segovia->id, $servicio->paraFecha($centro, Carbon::parse('2026-02-01'))->id);
        $this->assertTrue(app(NagerFestivos::class)->coberturaConfirmada($centro, 2026));
    }

    public function test_una_carga_desde_febrero_sin_enero_confirmado_no_genera_faltas_ni_correos_en_enero(): void
    {
        $this->travelTo(Carbon::parse('2026-01-15 10:00:00', 'Europe/Madrid'));
        Mail::fake();
        $centro = Centro::query()->where('name', 'Madrid')->firstOrFail();
        $ana = User::factory()->create(['centro_id' => $centro->id]);
        Horario::create(['user_id' => $ana->id, 'morning_start' => '09:00', 'morning_end' => '14:00', 'afternoon_start' => '15:00', 'afternoon_end' => '18:00', 'effective_from' => '2026-01-07']);
        $segovia = $this->importacion($centro, 'Segovia', '2026-02-01', []);
        app(VigenciasCalendario::class)->activar($segovia);
        $servicio = app(JornadaService::class);

        $this->assertFalse(app(NagerFestivos::class)->coberturaConfirmada($centro, 2026));
        $this->assertFalse($servicio->esDiaEsperado($ana, now()));
        $this->assertTrue($servicio->esDiaEsperado($ana, Carbon::parse('2026-02-02')));
        $this->assertSame('desconocido', $servicio->expectativaDe($ana, now())['estado']);
        $this->actingAs($ana)->get(route('jornada'))->assertOk()->assertViewHas('puedeEntrar', true);
        $this->artisan('jornada:avisar-inicio')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertDatabaseCount('avisos', 0);
    }

    public function test_no_permite_aplicar_un_candidato_sin_confirmar(): void
    {
        $centro = Centro::query()->where('name', 'Madrid')->firstOrFail();
        $importacion = $this->importacion($centro, 'Madrid', '2026-01-01', []);
        $importacion->update(['estado' => 'pendiente_revision', 'activado_at' => null]);
        $this->expectException(InvalidArgumentException::class);

        app(VigenciasCalendario::class)->activar($importacion);
    }

    public function test_no_permite_una_fecha_de_otro_anio(): void
    {
        $centro = Centro::query()->where('name', 'Madrid')->firstOrFail();
        $importacion = $this->importacion($centro, 'Madrid', '2026-01-01', []);
        $importacion->update(['vigente_desde' => '2027-01-01']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('año del calendario');

        app(VigenciasCalendario::class)->activar($importacion);
    }

    public function test_la_migracion_materializa_los_calendarios_activos_existentes(): void
    {
        $centro = Centro::query()->where('name', 'Madrid')->firstOrFail();
        $madrid = $this->importacion($centro, 'Madrid', '2026-01-01', [], true);
        $migracion = require database_path('migrations/2026_10_30_184152_create_vigencias_calendario_table.php');
        $migracion->down();
        $this->assertFalse(Schema::hasColumn('importaciones_calendario', 'vigente_desde'));

        $migracion->up();

        $tramo = app(VigenciasCalendario::class)->delAnio($centro, 2026)->sole();
        $this->assertTrue($tramo->exists);
        $this->assertSame($madrid->id, $tramo->importacion_id);
        $this->assertSame('2026-01-01', $tramo->desde->toDateString());
        $this->assertSame('2026-12-31', $tramo->hasta->toDateString());
        $this->assertSame('2026-01-01', $madrid->fresh()->vigente_desde->toDateString());
    }

    public function test_los_festivos_manuales_conservan_el_contexto_historico_sin_inventar_cobertura(): void
    {
        $this->travelTo(Carbon::parse('2026-10-30 12:00:00', 'Europe/Madrid'));
        $centro = Centro::query()->where('name', 'Madrid')->firstOrFail();
        $ana = User::factory()->create(['centro_id' => $centro->id, 'starts_on' => '2026-01-01']);
        $vacacion = Ausencia::create(['user_id' => $ana->id, 'absence_date' => '2026-01-20', 'tipo' => 'vacaciones']);
        $jornada = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-01-19']);
        $trabajo = Tramo::create(['jornada_id' => $jornada->id, 'tipo' => Tramo::TRABAJO, 'started_at' => '2026-01-19 09:00:00', 'ended_at' => '2026-01-19 18:00:00']);
        Festivo::create(['holiday_date' => '2026-01-05', 'name' => 'Festivo manual de enero', 'ambito' => 'pueblo', 'municipality' => 'Madrid', 'centro_id' => $centro->id]);
        Festivo::create(['holiday_date' => '2026-11-09', 'name' => 'Almudena manual', 'ambito' => 'pueblo', 'municipality' => 'Madrid', 'centro_id' => $centro->id]);
        $servicio = app(VigenciasCalendario::class);

        $servicio->conservarManuales($centro);
        $servicio->conservarManuales($centro);
        $contexto = ImportacionCalendario::query()->sole();
        $centro->update(['municipality' => 'Segovia', 'community' => 'Castilla y León']);
        $servicio->invalidar($centro, now());

        $this->assertSame('contexto_manual', $contexto->estado);
        $this->assertSame('00000', $contexto->municipio_ine);
        $this->assertSame('Madrid', $contexto->municipio);
        $this->assertNull($contexto->clave_activa);
        $this->assertSame('', $contexto->fuentes[0]['url']);
        $this->assertDatabaseCount('importaciones_calendario', 1);
        $this->assertSame($contexto->id, $servicio->paraFecha($centro, Carbon::parse('2026-01-05'))->id);
        $festivos = app(NagerFestivos::class);
        $this->assertTrue($festivos->es($centro, Carbon::parse('2026-01-05')));
        $this->assertFalse($festivos->es($centro, Carbon::parse('2026-11-09')));
        $this->assertFalse($festivos->coberturaConfirmada($centro, 2026));
        $this->assertFalse($festivos->coberturaConfirmada($centro, 2026, Carbon::parse('2026-01-19')));
        $this->assertSame('festivo', app(JornadaService::class)->expectativaDe($ana, Carbon::parse('2026-01-05'))['motivo']);
        $this->assertModelExists($vacacion);
        $this->assertModelExists($trabajo);
        $this->assertSame('2026-01-20', $vacacion->fresh()->absence_date->toDateString());
        $this->assertSame(540, app(JornadaService::class)->minutosRegistrados($ana, $jornada->fresh('tramos')));
    }

    public function test_una_importacion_confirmada_recorta_el_contexto_manual_y_solo_confirma_su_periodo(): void
    {
        $centro = Centro::query()->where('name', 'Madrid')->firstOrFail();
        Festivo::create(['holiday_date' => '2026-01-05', 'name' => 'Fiesta manual anterior', 'ambito' => 'pueblo', 'municipality' => 'Madrid', 'centro_id' => $centro->id]);
        Festivo::create(['holiday_date' => '2026-03-05', 'name' => 'Fiesta de otro municipio', 'ambito' => 'pueblo', 'municipality' => 'Barcelona']);
        $servicio = app(VigenciasCalendario::class);
        $servicio->conservarManuales($centro);
        $this->assertCount(1, ImportacionCalendario::query()->sole()->festivos);
        $segovia = $this->importacion($centro, 'Segovia', '2026-02-01', []);

        $servicio->activar($segovia);

        $festivos = app(NagerFestivos::class);
        $this->assertTrue($festivos->es($centro, Carbon::parse('2026-01-05')));
        $this->assertFalse($festivos->coberturaConfirmada($centro, 2026, Carbon::parse('2026-01-15')));
        $this->assertTrue($festivos->coberturaConfirmada($centro, 2026, Carbon::parse('2026-02-01')));
        $this->assertFalse($festivos->coberturaConfirmada($centro, 2026));
        $this->assertCount(2, $servicio->delAnio($centro, 2026));
    }

    /** @param list<array{date:string,name:string,ambito:string}> $dias */
    private function importacion(Centro $centro, string $municipio, string $desde, array $dias, bool $legacy = false): ImportacionCalendario
    {
        $anio = (int) substr($desde, 0, 4);
        $territorio = match ($municipio) {
            'Segovia' => ['40194', 'Segovia', 'Castilla y León', 'ES-CL'],
            'València' => ['46250', 'Valencia/València', 'Comunitat Valenciana', 'ES-VC'],
            default => ['28079', 'Madrid', 'Comunidad de Madrid', 'ES-MD'],
        };

        return ImportacionCalendario::query()->create([
            'centro_id' => $centro->id, 'anio' => $anio, 'municipio_ine' => $territorio[0], 'municipio' => $municipio,
            'provincia' => $territorio[1], 'comunidad' => $territorio[2], 'comunidad_codigo' => $territorio[3],
            'estado' => 'cargado', 'mensaje' => 'Calendario confirmado de prueba', 'festivos' => $dias, 'fuentes' => [],
            'hash' => hash('sha256', json_encode([$municipio, $desde, $dias])), 'consultado_at' => now(), 'activado_at' => now(),
            'clave_activa' => $legacy ? $centro->id.':'.$anio : null, 'vigente_desde' => $desde,
        ]);
    }
}
