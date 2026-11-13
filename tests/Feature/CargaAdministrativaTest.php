<?php

namespace Tests\Feature;

use App\Exceptions\ReglaJornada;
use App\Models\Ausencia;
use App\Models\CargaAdministrativaDia;
use App\Models\CargaAdministrativaEvento;
use App\Models\CargaAdministrativaLote;
use App\Models\Centro;
use App\Models\Festivo;
use App\Models\Jornada;
use App\Models\SolicitudVacaciones;
use App\Models\Tramo;
use App\Models\User;
use App\Services\CargaAdministrativa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CargaAdministrativaTest extends TestCase
{
    use RefreshDatabase;

    private User $tecnico;

    private User $jefe;

    private User $persona;

    private array $horario = ['morning_start' => '09:00', 'morning_end' => '14:00', 'afternoon_start' => '15:00', 'afternoon_end' => '18:00'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-06 10:00:00', 'Europe/Madrid'));
        $centro = Centro::updateOrCreate(['name' => 'Madrid'], ['municipality' => 'Madrid', 'community' => 'Comunidad de Madrid',
            'municipio_ine' => '28079', 'comunidad_codigo' => 'ES-MD', 'province' => 'Madrid', 'province_code' => '28']);
        $this->tecnico = User::factory()->soloTecnico()->create();
        $this->jefe = User::factory()->jefa()->create(['centro_id' => $centro->id]);
        $this->persona = User::factory()->create(['centro_id' => $centro->id, 'starts_on' => '2026-01-01', 'ends_on' => null]);
    }

    public function test_preview_distingue_pasado_y_prevision_y_excluye_vacaciones_festivos_finde_y_registros(): void
    {
        SolicitudVacaciones::create(['user_id' => $this->persona->id, 'starts_on' => '2026-11-06', 'ends_on' => '2026-11-06', 'estado' => 'pendiente']);
        Ausencia::create(['user_id' => $this->persona->id, 'absence_date' => '2026-11-10', 'tipo' => 'vacaciones']);
        Jornada::create(['user_id' => $this->persona->id, 'work_date' => '2026-11-11']);
        $plan = $this->vista('2026-11-05', '2026-11-11');

        $this->assertSame(['2026-11-05', '2026-11-06'], array_column($plan['dias'], 'fecha'));
        $this->assertSame(['historica', 'prevision'], array_column($plan['dias'], 'clase'));
        $this->assertSame(960, $plan['minutos']);
        $this->assertSame(1, $plan['vacaciones_pendientes_incluidas']);
        $this->assertSame(['fuera_periodo' => 0, 'descanso' => 2, 'festivo' => 1, 'ausencia' => 1, 'jornada_existente' => 1, 'carga_existente' => 0], $plan['exclusiones']);
    }

    public function test_aplicar_es_atomico_idempotente_y_separa_todas_las_cargas_de_jornadas(): void
    {
        $plan = $this->vista();
        $servicio = app(CargaAdministrativa::class);
        $lote = $servicio->aplicar($this->tecnico, [$this->persona->id], '2026-11-05', '2026-11-10', $this->horario, $plan['hash'], 'Carga inicial autorizada');
        $segunda = $servicio->aplicar($this->tecnico, [$this->persona->id], '2026-11-05', '2026-11-10', $this->horario, $plan['hash'], 'Carga inicial autorizada');

        $this->assertSame($lote->id, $segunda->id);
        $this->assertDatabaseCount('carga_administrativa_lotes', 1);
        $this->assertDatabaseCount('carga_administrativa_dias', 3);
        $this->assertDatabaseCount('carga_administrativa_eventos', 3);
        $this->assertDatabaseCount('jornadas', 0);
        $this->assertDatabaseCount('tramos', 0);
        $this->assertSame($this->tecnico->id, $lote->autor_id);
        $this->assertSame('Carga inicial autorizada', $lote->motivo);
        $this->assertSame($plan['fuentes'], $lote->referencias);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $lote->referencias[2026]['snapshot']);
        $resumen = $servicio->resumen($this->persona, '2026-11-01', '2026-11-30');
        $this->assertSame(1440, $resumen['minutos']);
        $this->assertSame(480, $resumen['minutos_historicos']);
        $this->assertSame(960, $resumen['minutos_prevision']);
    }

    public function test_previsualizacion_caducada_no_crea_lote_si_aparece_una_ausencia(): void
    {
        $plan = $this->vista();
        Ausencia::create(['user_id' => $this->persona->id, 'absence_date' => '2026-11-06', 'tipo' => 'vacaciones']);
        try {
            app(CargaAdministrativa::class)->aplicar($this->tecnico, [$this->persona->id], '2026-11-05', '2026-11-10', $this->horario, $plan['hash'], 'Carga autorizada');
            $this->fail('La carga debió rechazar la vista desactualizada.');
        } catch (ReglaJornada $e) {
            $this->assertStringContainsString('cambiado', $e->getMessage());
        }
        $this->assertDatabaseCount('carga_administrativa_lotes', 0);
        $this->assertDatabaseCount('carga_administrativa_dias', 0);
    }

    public function test_aprobar_y_corregir_ausencia_anula_y_restablece_con_historial(): void
    {
        $this->aplicar();
        $servicio = app(CargaAdministrativa::class);
        $ausencia = Ausencia::create(['user_id' => $this->persona->id, 'absence_date' => '2026-11-06', 'tipo' => 'vacaciones']);
        $servicio->cancelarPorAusencia($this->jefe, $this->persona, '2026-11-06', 'Vacaciones aprobadas');
        $servicio->cancelarPorAusencia($this->jefe, $this->persona, '2026-11-06', 'Vacaciones aprobadas');
        $dia = CargaAdministrativaDia::query()->whereDate('fecha', '2026-11-06')->sole();
        $this->assertSame('anulada_ausencia', $dia->estado);
        $this->assertNull($dia->clave_activa);
        $this->assertSame(960, $servicio->resumen($this->persona, '2026-11-01', '2026-11-30')['minutos']);
        $ausencia->delete();
        $servicio->restaurarTrasCorregirAusencia($this->jefe, $this->persona, '2026-11-06', 'Corrección de vacaciones');
        $this->assertSame('vigente', $dia->fresh()->estado);
        $this->assertSame(1440, $servicio->resumen($this->persona, '2026-11-01', '2026-11-30')['minutos']);
        $this->assertSame(['creada', 'anulada_ausencia', 'restaurada'], $dia->eventos()->orderBy('id')->pluck('tipo')->all());
    }

    public function test_fichaje_real_sustituye_carga_sin_borrarla_ni_contarla_dos_veces(): void
    {
        $this->aplicar();
        $jornada = Jornada::create(['user_id' => $this->persona->id, 'work_date' => '2026-11-06']);
        Tramo::create(['jornada_id' => $jornada->id, 'tipo' => 'trabajo', 'started_at' => '2026-11-06 09:05']);
        $servicio = app(CargaAdministrativa::class);
        // Incluso si un flujo legado no ha ejecutado el hook, no suma ambas fuentes.
        $this->assertSame(960, $servicio->resumen($this->persona, '2026-11-01', '2026-11-30')['minutos']);
        $servicio->sustituirPorFichaje($this->persona, $this->persona, '2026-11-06', $jornada);
        $servicio->sustituirPorFichaje($this->persona, $this->persona, '2026-11-06', $jornada);
        $dia = CargaAdministrativaDia::query()->whereDate('fecha', '2026-11-06')->sole();
        $this->assertSame('sustituida', $dia->estado);
        $this->assertSame($jornada->id, $dia->jornada_sustituta_id);
        $this->assertSame(['creada', 'sustituida'], $dia->eventos()->orderBy('id')->pluck('tipo')->all());
        $this->assertSame($this->persona->id, $dia->eventos()->latest('id')->first()->actor_id);
        $this->assertDatabaseCount('tramos', 1);
    }

    public function test_ausencia_no_puede_borrar_ni_ocultar_un_fichaje_real(): void
    {
        $this->aplicar();
        $jornada = Jornada::create(['user_id' => $this->persona->id, 'work_date' => '2026-11-06']);
        Tramo::create(['jornada_id' => $jornada->id, 'tipo' => 'trabajo', 'started_at' => '2026-11-06 09:05']);
        try {
            DB::transaction(function (): void {
                Ausencia::create(['user_id' => $this->persona->id, 'absence_date' => '2026-11-06', 'tipo' => 'vacaciones']);
                app(CargaAdministrativa::class)->cancelarPorAusencia($this->jefe, $this->persona, '2026-11-06', 'Vacaciones aprobadas');
            });
            $this->fail('El conflicto debía rechazarse.');
        } catch (ReglaJornada $e) {
            $this->assertStringContainsString('fichaje real', $e->getMessage());
        }
        $this->assertDatabaseCount('ausencias', 0);
        $this->assertDatabaseCount('tramos', 1);
        $this->assertSame('vigente', CargaAdministrativaDia::query()->whereDate('fecha', '2026-11-06')->sole()->estado);
    }

    public function test_no_se_da_acceso_a_trabajador_ni_a_tecnico_para_aprobar_ausencias(): void
    {
        try {
            app(CargaAdministrativa::class)->previsualizar($this->persona, [$this->persona->id], '2026-11-05', '2026-11-10', $this->horario);
            $this->fail('Una cuenta laboral no puede preparar cargas.');
        } catch (ReglaJornada $e) {
            $this->assertStringContainsString('responsable', $e->getMessage());
        }
        $this->expectException(ReglaJornada::class);
        app(CargaAdministrativa::class)->cancelarPorAusencia($this->tecnico, $this->persona, '2026-11-06', 'No autorizado');
    }

    public function test_los_festivos_manuales_confirmados_tambien_se_respetan(): void
    {
        Festivo::create(['centro_id' => $this->persona->centro_id, 'municipality' => 'Madrid', 'holiday_date' => '2026-11-06', 'name' => 'Cierre local', 'ambito' => 'pueblo']);
        $plan = $this->vista();
        $this->assertNotContains('2026-11-06', array_column($plan['dias'], 'fecha'));
        $this->assertSame(2, $plan['exclusiones']['festivo']);
    }

    public function test_la_vista_cambia_si_aparece_un_fichaje_y_no_hay_inserciones_parciales(): void
    {
        $plan = $this->vista();
        Jornada::create(['user_id' => $this->persona->id, 'work_date' => '2026-11-06']);
        try {
            app(CargaAdministrativa::class)->aplicar($this->tecnico, [$this->persona->id], '2026-11-05', '2026-11-10', $this->horario, $plan['hash'], 'Carga inicial autorizada');
            $this->fail('El nuevo registro debe invalidar la vista previa.');
        } catch (ReglaJornada $e) {
            $this->assertStringContainsString('cambiado', $e->getMessage());
        }
        $this->assertDatabaseCount('carga_administrativa_lotes', 0);
        $this->assertDatabaseCount('carga_administrativa_eventos', 0);
        $this->assertDatabaseCount('jornadas', 1);
    }

    public function test_no_se_puede_reutilizar_el_hash_con_otro_motivo_o_responsable(): void
    {
        $plan = $this->vista();
        $servicio = app(CargaAdministrativa::class);
        $servicio->aplicar($this->tecnico, [$this->persona->id], '2026-11-05', '2026-11-10', $this->horario, $plan['hash'], 'Carga autorizada');
        foreach ([[$this->tecnico, 'Motivo cambiado'], [$this->jefe, 'Carga autorizada']] as [$actor, $motivo]) {
            try {
                $servicio->aplicar($actor, [$this->persona->id], '2026-11-05', '2026-11-10', $this->horario, $plan['hash'], $motivo);
                $this->fail('No se puede reutilizar el hash con otra autorización.');
            } catch (ReglaJornada $e) {
                $this->assertStringContainsString('otros parámetros', $e->getMessage());
            }
        }
        $this->assertDatabaseCount('carga_administrativa_lotes', 1);
        $this->assertDatabaseCount('carga_administrativa_dias', 3);
    }

    public function test_se_rechazan_referencias_inexistentes_horarios_invalidos_y_cuentas_tecnicas(): void
    {
        $servicio = app(CargaAdministrativa::class);
        $casos = [
            [[$this->persona->id], '2028-01-03', '2028-01-03', $this->horario],
            [[$this->persona->id], '2026-02-30', '2026-03-01', $this->horario],
            [[$this->persona->id], '2026-11-05', '2026-11-10', array_merge($this->horario, ['morning_end' => '18:00'])],
            [[$this->tecnico->id], '2026-11-05', '2026-11-10', $this->horario],
        ];
        foreach ($casos as [$ids, $desde, $hasta, $horario]) {
            try {
                $servicio->previsualizar($this->tecnico, $ids, $desde, $hasta, $horario);
                $this->fail('El caso no debe aceptarse.');
            } catch (ReglaJornada $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertDatabaseCount('carga_administrativa_lotes', 0);
    }

    public function test_el_resumen_no_suma_una_ausencia_aunque_falte_el_hook(): void
    {
        $this->aplicar();
        Ausencia::create(['user_id' => $this->persona->id, 'absence_date' => '2026-11-06', 'tipo' => 'vacaciones']);
        $resumen = app(CargaAdministrativa::class)->resumen($this->persona, '2026-11-01', '2026-11-30');
        $this->assertSame(960, $resumen['minutos']);
        $this->assertSame('vigente', CargaAdministrativaDia::query()->whereDate('fecha', '2026-11-06')->sole()->estado);
    }

    public function test_no_se_restablece_una_carga_en_un_dia_que_se_ha_convertido_en_festivo(): void
    {
        $this->aplicar();
        $servicio = app(CargaAdministrativa::class);
        $ausencia = Ausencia::create(['user_id' => $this->persona->id, 'absence_date' => '2026-11-06', 'tipo' => 'vacaciones']);
        $servicio->cancelarPorAusencia($this->jefe, $this->persona, '2026-11-06', 'Vacaciones');
        $ausencia->delete();
        Festivo::create(['centro_id' => $this->persona->centro_id, 'municipality' => 'Madrid', 'holiday_date' => '2026-11-06', 'name' => 'Cierre', 'ambito' => 'pueblo']);
        try {
            $servicio->restaurarTrasCorregirAusencia($this->jefe, $this->persona, '2026-11-06', 'Corrección');
            $this->fail('No debe restaurarse en un día no laborable.');
        } catch (ReglaJornada $e) {
            $this->assertStringContainsString('condiciones', $e->getMessage());
        }
        $this->assertSame('anulada_ausencia', CargaAdministrativaDia::query()->whereDate('fecha', '2026-11-06')->sole()->estado);
    }

    public function test_el_resumen_no_cuenta_cargas_posteriores_al_fin_laboral(): void
    {
        $this->aplicar();
        $this->persona->update(['ends_on' => '2026-11-06']);
        $this->assertSame(960, app(CargaAdministrativa::class)->resumen($this->persona->fresh(), '2026-11-01', '2026-11-30')['minutos']);
    }

    public function test_periodo_completo_confirmado_respeta_altas_y_festivos_oficiales(): void
    {
        $this->jefe->update(['starts_on' => '2026-01-01']);
        $this->persona->update(['starts_on' => '2026-09-14']);
        $tercero = User::factory()->create(['centro_id' => $this->persona->centro_id, 'starts_on' => '2026-01-01']);
        $plan = app(CargaAdministrativa::class)->previsualizar(
            $this->tecnico, [$this->jefe->id, $this->persona->id, $tercero->id], '2026-01-01', '2026-11-30', $this->horario,
        );
        $conteos = collect($plan['dias'])->groupBy('user_id')->map->count();
        $this->assertSame(229, $conteos[$this->jefe->id]);
        $this->assertSame(53, $conteos[$this->persona->id]);
        $this->assertSame(229, $conteos[$tercero->id]);
        $this->assertSame(511 * 480, $plan['minutos']);
        $this->assertSame(463, collect($plan['dias'])->where('clase', 'historica')->count());
        $this->assertSame(48, collect($plan['dias'])->where('clase', 'prevision')->count());
        $this->assertNotContains('2026-11-09', array_column($plan['dias'], 'fecha'));
        $this->assertDatabaseCount('jornadas', 0);
    }

    public function test_los_eventos_de_auditoria_no_admiten_edicion_ni_borrado(): void
    {
        $this->aplicar();
        $evento = CargaAdministrativaEvento::firstOrFail();
        try {
            $evento->update(['motivo' => 'Alterado']);
            $this->fail('No se debe editar un evento histórico.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('no se modifican', $e->getMessage());
        }
        try {
            $evento->delete();
            $this->fail('No se debe borrar un evento histórico.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('no se eliminan', $e->getMessage());
        }
        $this->assertSame('Carga inicial autorizada', $evento->fresh()->motivo);
        $this->assertDatabaseCount('carga_administrativa_eventos', 3);
    }

    private function vista(string $desde = '2026-11-05', string $hasta = '2026-11-10'): array
    {
        return app(CargaAdministrativa::class)->previsualizar($this->tecnico, [$this->persona->id], $desde, $hasta, $this->horario);
    }

    private function aplicar(): CargaAdministrativaLote
    {
        $plan = $this->vista();

        return app(CargaAdministrativa::class)->aplicar($this->tecnico, [$this->persona->id], '2026-11-05', '2026-11-10', $this->horario, $plan['hash'], 'Carga inicial autorizada');
    }
}
