<?php

namespace Tests\Feature;

use App\Exceptions\ReglaJornada;
use App\Models\AsignacionCentro;
use App\Models\Ausencia;
use App\Models\Centro;
use App\Models\ImportacionCalendario;
use App\Models\SolicitudVacaciones;
use App\Models\User;
use App\Services\AjustarSaldoVacaciones;
use App\Services\CupoVacaciones;
use App\Services\VigenciasCalendario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class CoberturaVacacionesTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_acepta_dias_laborables_de_2028_sin_calendario(): void
    {
        $persona = User::factory()->create(['centro_id' => $this->madrid()->id]);
        $this->expectException(ReglaJornada::class);
        $this->expectExceptionMessage('falta confirmar el calendario laboral del 03/01/2028');
        app(CupoVacaciones::class)->asegurarCobertura($persona, Carbon::parse('2028-01-03'), Carbon::parse('2028-01-03'));
    }

    public function test_vigencia_parcial_permite_los_dias_cubiertos_sin_exigir_todo_el_ano(): void
    {
        $centro = $this->madrid();
        $persona = User::factory()->create(['centro_id' => $centro->id]);
        $this->activar($centro, '2026-11-06');
        $desde = Carbon::parse('2026-11-06 10:30');
        $hasta = Carbon::parse('2026-11-10 14:00');
        app(CupoVacaciones::class)->asegurarCobertura($persona, $desde, $hasta);
        $this->assertSame('2026-11-06 10:30', $desde->format('Y-m-d H:i'));
        $this->assertSame('2026-11-10 14:00', $hasta->format('Y-m-d H:i'));
        $this->assertDatabaseCount('solicitudes_vacaciones', 0);
    }

    public function test_vigencia_parcial_rechaza_el_dia_anterior_no_cubierto(): void
    {
        $centro = $this->madrid();
        $persona = User::factory()->create(['centro_id' => $centro->id]);
        $this->activar($centro, '2026-11-06');
        $this->expectException(ReglaJornada::class);
        $this->expectExceptionMessage('05/11/2026');
        app(CupoVacaciones::class)->asegurarCobertura($persona, Carbon::parse('2026-11-05'), Carbon::parse('2026-11-06'));
    }

    public function test_el_cambio_de_sede_exige_cobertura_en_la_sede_vigente_cada_dia(): void
    {
        $madrid = $this->madrid();
        $segovia = Centro::create(['name' => 'Segovia', 'municipality' => 'Segovia', 'community' => 'Castilla y León']);
        $persona = User::factory()->create(['centro_id' => $madrid->id]);
        AsignacionCentro::create(['user_id' => $persona->id, 'centro_id' => $madrid->id, 'desde' => '2026-01-01', 'hasta' => '2026-11-08']);
        AsignacionCentro::create(['user_id' => $persona->id, 'centro_id' => $segovia->id, 'desde' => '2026-11-09']);
        $this->calendarioConfirmado($madrid, 2026);
        $cupo = app(CupoVacaciones::class);
        try {
            $cupo->asegurarCobertura($persona, Carbon::parse('2026-11-06'), Carbon::parse('2026-11-10'));
            $this->fail('Se aceptó una sede nueva sin cobertura.');
        } catch (ReglaJornada $e) {
            $this->assertStringContainsString('09/11/2026', $e->getMessage());
        }
        $this->calendarioConfirmado($segovia, 2026);
        $cupo->asegurarCobertura($persona, Carbon::parse('2026-11-06'), Carbon::parse('2026-11-10'));
        $this->assertTrue(true);
    }

    public function test_cruce_de_anos_no_acepta_2028_sin_confirmacion_aunque_2027_este_cubierto(): void
    {
        $centro = $this->madrid();
        $persona = User::factory()->create(['centro_id' => $centro->id]);
        $this->calendarioConfirmado($centro, 2027);
        $this->expectException(ReglaJornada::class);
        $this->expectExceptionMessage('03/01/2028');
        app(CupoVacaciones::class)->asegurarCobertura($persona, Carbon::parse('2027-12-31'), Carbon::parse('2028-01-03'));
    }

    public function test_un_fin_de_semana_no_exige_cobertura_si_no_hay_dias_laborables(): void
    {
        $persona = User::factory()->create(['centro_id' => $this->madrid()->id]);
        app(CupoVacaciones::class)->asegurarCobertura($persona, Carbon::parse('2028-01-01'), Carbon::parse('2028-01-02'));
        $this->assertDatabaseCount('solicitudes_vacaciones', 0);
    }

    public function test_confirmar_saldo_inicial_sin_registros_no_exige_calendario(): void
    {
        $persona = User::factory()->create(['centro_id' => $this->madrid()->id]);
        $jefe = User::factory()->jefa()->create();
        $this->ajustar($jefe, $persona, 2028, 10, 0);
        $this->assertDatabaseHas('ajustes_saldo_vacaciones', ['user_id' => $persona->id, 'anio' => 2028, 'saldo_deseado' => 10]);
    }

    public function test_no_confirma_saldo_si_una_vacacion_aprobada_no_tiene_cobertura(): void
    {
        $persona = User::factory()->create(['centro_id' => $this->madrid()->id]);
        $jefe = User::factory()->jefa()->create();
        Ausencia::create(['user_id' => $persona->id, 'absence_date' => '2028-01-03', 'tipo' => 'vacaciones']);
        try {
            $this->ajustar($jefe, $persona, 2028, 10, 1);
            $this->fail('Se aceptó un saldo calculado sin calendario.');
        } catch (ReglaJornada $e) {
            $this->assertStringContainsString('03/01/2028', $e->getMessage());
        }
        $this->assertDatabaseCount('ajustes_saldo_vacaciones', 0);
    }

    public function test_no_confirma_saldo_si_hay_solicitud_pendiente_sin_cobertura(): void
    {
        $persona = User::factory()->create(['centro_id' => $this->madrid()->id]);
        $jefe = User::factory()->jefa()->create();
        SolicitudVacaciones::create(['user_id' => $persona->id, 'starts_on' => '2028-01-03', 'ends_on' => '2028-01-04', 'estado' => SolicitudVacaciones::PENDIENTE]);
        $this->actingAs($jefe)->get(route('equipo.vacaciones.saldo', ['user' => $persona, 'anio' => 2028]))
            ->assertOk()->assertSee('falta confirmar el calendario laboral del 03/01/2028')->assertDontSee('Confirmar saldo');
        $this->actingAs($jefe)->post(route('equipo.vacaciones.saldo.guardar', $persona), [
            'anio' => 2028, 'saldo' => 10, 'aprobados_esperados' => 0, 'ultimo_ajuste_esperado' => 0,
            'motivo' => 'Saldo verificado', 'operacion' => (string) Str::uuid(),
        ])->assertRedirect()->assertSessionHas('aviso', fn (string $texto) => str_contains($texto, '03/01/2028'));
        $this->assertDatabaseCount('ajustes_saldo_vacaciones', 0);
    }

    public function test_confirma_saldo_si_solo_los_dias_afectados_estan_cubiertos(): void
    {
        $centro = $this->madrid();
        $persona = User::factory()->create(['centro_id' => $centro->id]);
        $jefe = User::factory()->jefa()->create();
        $this->activar($centro, '2026-11-06');
        Ausencia::create(['user_id' => $persona->id, 'absence_date' => '2026-11-06', 'tipo' => 'vacaciones']);
        SolicitudVacaciones::create(['user_id' => $persona->id, 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-10', 'estado' => SolicitudVacaciones::PENDIENTE]);
        $this->ajustar($jefe, $persona, 2026, 10, 1);
        $this->assertDatabaseHas('ajustes_saldo_vacaciones', ['user_id' => $persona->id, 'anio' => 2026, 'aprobados_al_ajuste' => 1]);
    }

    public function test_solicitud_entre_anos_se_comprueba_solo_en_el_ano_del_ajuste(): void
    {
        $centro = $this->madrid();
        $persona = User::factory()->create(['centro_id' => $centro->id]);
        $jefe = User::factory()->jefa()->create();
        $this->activar($centro, '2027-12-31');
        SolicitudVacaciones::create(['user_id' => $persona->id, 'starts_on' => '2027-12-31', 'ends_on' => '2028-01-03', 'estado' => SolicitudVacaciones::PENDIENTE]);
        $this->ajustar($jefe, $persona, 2027, 10, 0);
        $this->assertDatabaseCount('ajustes_saldo_vacaciones', 1);
        try {
            $this->ajustar($jefe, $persona, 2028, 10, 0);
            $this->fail('Se aceptó una solicitud del año no cubierto.');
        } catch (ReglaJornada $e) {
            $this->assertStringContainsString('03/01/2028', $e->getMessage());
        }
        $this->assertDatabaseCount('ajustes_saldo_vacaciones', 1);
    }

    private function ajustar(User $jefe, User $persona, int $anio, int $saldo, int $aprobados): void
    {
        app(AjustarSaldoVacaciones::class)->ajustar($jefe, $persona, $anio, $saldo, $aprobados, 0, 'Saldo confirmado', (string) Str::uuid());
    }

    private function madrid(): Centro
    {
        return Centro::query()->where('name', 'Madrid')->firstOrFail();
    }

    private function activar(Centro $centro, string $desde): void
    {
        $importacion = ImportacionCalendario::create([
            'centro_id' => $centro->id, 'anio' => (int) substr($desde, 0, 4), 'municipio_ine' => '28079',
            'municipio' => 'Madrid', 'provincia' => 'Madrid', 'comunidad' => 'Comunidad de Madrid',
            'comunidad_codigo' => 'ES-MD', 'estado' => 'cargado', 'mensaje' => 'Calendario de prueba',
            'festivos' => [], 'fuentes' => [], 'hash' => hash('sha256', $desde),
            'consultado_at' => now(), 'activado_at' => now(), 'vigente_desde' => $desde,
        ]);
        app(VigenciasCalendario::class)->activar($importacion);
    }
}
