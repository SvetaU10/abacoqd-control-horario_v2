<?php

namespace Tests\Feature;

use App\Exceptions\ReglaJornada;
use App\Models\AjusteSaldoVacaciones;
use App\Models\Ausencia;
use App\Models\SolicitudVacaciones;
use App\Models\User;
use App\Services\AjustarSaldoVacaciones;
use App\Services\CupoVacaciones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class AjusteSaldoVacacionesTest extends TestCase
{
    use RefreshDatabase;

    private User $jefe;

    private User $trabajador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-06 10:00:00', 'Europe/Madrid'));
        $this->jefe = User::factory()->jefa()->create();
        $this->trabajador = User::factory()->create(['starts_on' => '2026-01-01']);
    }

    public function test_saldo_desconocido_no_se_inventa_y_no_permite_solicitud(): void
    {
        $this->calendarioConfirmado($this->trabajador->sede, 2026);
        $cupo = app(CupoVacaciones::class);
        $this->assertNull($cupo->restantes($this->trabajador, 2026));
        $this->assertNull($cupo->disponibles($this->trabajador, 2026));
        $this->actingAs($this->trabajador)->get(route('calendario'))->assertOk()
            ->assertSee('Saldo sin confirmar')->assertDontSee('22 días laborables');
        $this->actingAs($this->trabajador)->post(route('calendario.vacaciones'), ['desde' => '2026-11-10', 'hasta' => '2026-11-10'])
            ->assertSessionHas('aviso', 'El saldo disponible es insuficiente o todavía no está confirmado.');
        $this->assertDatabaseCount('solicitudes_vacaciones', 0);
    }

    public function test_un_saldo_confirmado_descuenta_cambios_posteriores_y_reserva_pendientes(): void
    {
        $this->calendarioConfirmado($this->trabajador->sede, 2026);
        $this->ausencia('2026-11-02');
        $this->ausencia('2026-11-03');
        $this->ajustar(10, 2);
        SolicitudVacaciones::create(['user_id' => $this->trabajador->id, 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-11', 'estado' => SolicitudVacaciones::PENDIENTE]);
        $cupo = app(CupoVacaciones::class);
        $this->assertSame(10, $cupo->restantes($this->trabajador, 2026));
        $this->assertSame(8, $cupo->disponibles($this->trabajador, 2026));
        $this->ausencia('2026-11-04');
        $this->assertSame(9, $cupo->restantes($this->trabajador, 2026));
        $this->assertSame(7, $cupo->disponibles($this->trabajador, 2026));
        Ausencia::where('user_id', $this->trabajador->id)->whereDate('absence_date', '2026-11-04')->delete();
        $this->assertSame(10, $cupo->restantes($this->trabajador, 2026));
        $this->assertDatabaseCount('ajustes_saldo_vacaciones', 1);
    }

    public function test_pendientes_y_solicitudes_entre_anos_respetan_cada_saldo(): void
    {
        $this->ajustar(1, 0);
        SolicitudVacaciones::create(['user_id' => $this->trabajador->id, 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-10', 'estado' => SolicitudVacaciones::PENDIENTE]);
        $cupo = app(CupoVacaciones::class);
        $this->assertSame(0, $cupo->disponibles($this->trabajador, 2026));
        $this->assertFalse($cupo->cabe($this->trabajador, Carbon::parse('2026-11-11'), Carbon::parse('2026-11-11')));
        $this->assertFalse($cupo->cabe($this->trabajador, Carbon::parse('2026-12-31'), Carbon::parse('2027-01-04')));
    }

    public function test_un_desfase_posterior_muestra_el_deficit_al_responsable_sin_conceder_disponibilidad(): void
    {
        $this->calendarioConfirmado($this->trabajador->sede, 2026);
        $this->ajustar(0, 0);
        $this->ausencia('2026-11-02');
        $cupo = app(CupoVacaciones::class);
        $this->assertSame(-1, $cupo->restantes($this->trabajador, 2026));
        $this->assertSame(0, $cupo->disponibles($this->trabajador, 2026));
        $this->actingAs($this->jefe)->get(route('equipo.vacaciones.saldo', $this->trabajador))
            ->assertOk()->assertSee('-1 días');
    }

    public function test_solo_responsable_activo_ajusta_y_se_guarda_historial_sin_duplicar(): void
    {
        $datos = ['anio' => 2026, 'saldo' => 8, 'aprobados_esperados' => 0, 'ultimo_ajuste_esperado' => 0, 'motivo' => 'Saldo confirmado', 'operacion' => (string) Str::uuid()];
        $tecnico = User::factory()->soloTecnico()->create();
        foreach ([$this->trabajador, $tecnico] as $usuario) {
            $this->actingAs($usuario)->get(route('equipo.vacaciones.saldo', $this->jefe))->assertRedirect();
            $this->actingAs($usuario)->post(route('equipo.vacaciones.saldo.guardar', $this->trabajador), $datos)->assertRedirect();
        }
        $this->assertDatabaseCount('ajustes_saldo_vacaciones', 0);
        $this->actingAs($this->jefe)->get(route('equipo.vacaciones.saldo', $this->trabajador))->assertOk()->assertSee('Sin confirmar');
        $this->actingAs($this->jefe)->post(route('equipo.vacaciones.saldo.guardar', $this->trabajador), $datos)->assertSessionHas('ok');
        $this->actingAs($this->jefe)->post(route('equipo.vacaciones.saldo.guardar', $this->trabajador), $datos)->assertSessionHas('aviso');
        $datos['operacion'] = (string) Str::uuid();
        $this->actingAs($this->jefe)->post(route('equipo.vacaciones.saldo.guardar', $this->trabajador), $datos)
            ->assertSessionHas('aviso', 'El saldo ha cambiado. Revisa la información antes de guardar.');
        $this->assertDatabaseCount('ajustes_saldo_vacaciones', 1);
        $this->assertDatabaseHas('ajustes_saldo_vacaciones', ['actor_id' => $this->jefe->id, 'user_id' => $this->trabajador->id, 'saldo_deseado' => 8, 'aprobados_al_ajuste' => 0]);
    }

    public function test_un_ajuste_obsoleto_no_guarda_y_los_existentes_no_se_mutan(): void
    {
        $this->calendarioConfirmado($this->trabajador->sede, 2026);
        $primer = $this->ajustar(10, 0);
        $this->ausencia('2026-11-02');
        try {
            $this->ajustar(8, 0);
            $this->fail('Se aceptó un dato desactualizado.');
        } catch (ReglaJornada $e) {
            $this->assertStringContainsString('han cambiado', $e->getMessage());
        }
        $this->assertDatabaseCount('ajustes_saldo_vacaciones', 1);
        $this->expectException(\LogicException::class);
        $primer->update(['saldo_deseado' => 99]);
    }

    public function test_ausencia_manual_vacaciones_exige_saldo_pero_permiso_no(): void
    {
        $this->calendarioConfirmado($this->trabajador->sede, 2026);
        $this->actingAs($this->jefe)->post(route('equipo.ausencia', $this->trabajador), ['absence_date' => '2026-11-10', 'tipo' => 'vacaciones'])
            ->assertSessionHas('aviso', 'El saldo disponible es insuficiente o no está confirmado.');
        $this->actingAs($this->jefe)->post(route('equipo.ausencia', $this->trabajador), ['absence_date' => '2026-11-11', 'tipo' => 'permiso'])
            ->assertSessionHas('ok');
        $this->ajustar(1, 0);
        $this->actingAs($this->jefe)->post(route('equipo.ausencia', $this->trabajador), ['absence_date' => '2026-11-10', 'tipo' => 'vacaciones'])
            ->assertSessionHas('ok');
        $this->assertSame(0, app(CupoVacaciones::class)->restantes($this->trabajador, 2026));
    }

    private function ajustar(int $saldo, int $aprobados): AjusteSaldoVacaciones
    {
        $ultimo = (int) (AjusteSaldoVacaciones::where('user_id', $this->trabajador->id)->where('anio', 2026)->max('id') ?? 0);

        return app(AjustarSaldoVacaciones::class)->ajustar($this->jefe, $this->trabajador, 2026, $saldo, $aprobados, $ultimo, 'Revisión documentada', (string) Str::uuid());
    }

    private function ausencia(string $fecha): void
    {
        Ausencia::create(['user_id' => $this->trabajador->id, 'absence_date' => $fecha, 'tipo' => 'vacaciones']);
    }
}
