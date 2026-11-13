<?php

namespace Tests\Feature;

use App\Models\AjusteSaldoVacaciones;
use App\Models\Ausencia;
use App\Models\CargaAdministrativaDia;
use App\Models\Centro;
use App\Models\EventoAusencia;
use App\Models\Horario;
use App\Models\Jornada;
use App\Models\SolicitudVacaciones;
use App\Models\Tramo;
use App\Models\User;
use App\Services\CalendarioMadridOficial;
use App\Services\CargaAdministrativa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnulacionAusenciaTest extends TestCase
{
    use RefreshDatabase;

    private User $jefe;

    private User $persona;

    private User $tecnico;

    private array $horas = ['morning_start' => '09:00', 'morning_end' => '14:00', 'afternoon_start' => '15:00', 'afternoon_end' => '18:00'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-06 10:00:00', 'Europe/Madrid'));
        $centro = Centro::updateOrCreate(['name' => 'Madrid'], ['municipality' => 'Madrid', 'community' => 'Comunidad de Madrid', 'municipio_ine' => '28079', 'comunidad_codigo' => 'ES-MD', 'province' => 'Madrid', 'province_code' => '28']);
        $this->tecnico = User::factory()->soloTecnico()->create();
        $this->jefe = User::factory()->jefa()->create(['centro_id' => $centro->id]);
        $this->persona = User::factory()->create(['centro_id' => $centro->id, 'starts_on' => '2026-01-01', 'ends_on' => null]);
        Horario::create(['user_id' => $this->persona->id, 'effective_from' => '2026-01-01', 'tipo' => 'partida', ...$this->horas]);
        app(CalendarioMadridOficial::class)->preparar($centro, $this->tecnico, '2026-01-01', 2027);
    }

    public function test_el_responsable_anula_ausencia_y_restaura_solo_la_carga_vinculada_con_historial(): void
    {
        $this->cargar('2026-11-06');
        $this->actingAs($this->jefe)->post(route('equipo.ausencia', $this->persona), ['absence_date' => '2026-11-06', 'tipo' => 'permiso'])->assertSessionHas('ok');
        $ausencia = Ausencia::query()->sole();
        $id = $ausencia->id;
        $dia = CargaAdministrativaDia::query()->sole();
        $this->assertSame('anulada_ausencia', $dia->estado);
        $this->post(route('equipo.ausencia.anular', [$this->persona, $ausencia]), ['motivo' => 'Fecha introducida por error'])->assertSessionHas('ok');
        $this->assertDatabaseMissing('ausencias', ['id' => $id]);
        $this->assertSame('vigente', $dia->fresh()->estado);
        $this->assertDatabaseHas('eventos_ausencias', ['ausencia_original_id' => $id, 'user_id' => $this->persona->id,
            'actor_id' => $this->jefe->id, 'motivo' => 'Fecha introducida por error', 'carga_dia_id' => $dia->id]);
        $this->assertSame(['creada', 'anulada_ausencia', 'restaurada'], $dia->eventos()->orderBy('id')->pluck('tipo')->all());
        $this->assertDatabaseCount('jornadas', 0);
        $this->get(route('equipo.show', $this->persona))->assertOk()->assertSee('Historial de correcciones de ausencias')->assertSee('Fecha introducida por error');
    }

    public function test_dos_ciclos_en_la_misma_fecha_conservan_auditoria_y_relacion(): void
    {
        $this->cargar('2026-11-06');
        foreach (['Error uno', 'Error dos'] as $motivo) {
            $this->actingAs($this->jefe)->post(route('equipo.ausencia', $this->persona), ['absence_date' => '2026-11-06', 'tipo' => 'permiso'])->assertSessionHas('ok');
            $ausencia = Ausencia::query()->sole();
            $this->post(route('equipo.ausencia.anular', [$this->persona, $ausencia]), ['motivo' => $motivo])->assertSessionHas('ok');
        }
        $this->assertDatabaseCount('eventos_ausencias', 2);
        $this->assertCount(2, EventoAusencia::query()->distinct()->pluck('ausencia_original_id'));
        $this->assertSame('vigente', CargaAdministrativaDia::query()->sole()->estado);
        $this->assertDatabaseCount('ausencias', 0);
    }

    public function test_permisos_motivo_y_usuario_coincidente_se_comprueban(): void
    {
        $ausencia = Ausencia::create(['user_id' => $this->persona->id, 'absence_date' => '2026-11-06', 'tipo' => 'permiso']);
        foreach ([$this->persona, $this->tecnico] as $usuario) {
            $this->actingAs($usuario)->post(route('equipo.ausencia.anular', [$this->persona, $ausencia]), ['motivo' => 'Motivo de prueba'])->assertRedirect(route($usuario->paginaInicio()));
        }
        $otro = User::factory()->create();
        $this->actingAs($this->jefe)->post(route('equipo.ausencia.anular', [$otro, $ausencia]), ['motivo' => 'Motivo de prueba'])->assertNotFound();
        $this->post(route('equipo.ausencia.anular', [$this->persona, $ausencia]), ['motivo' => ' '])->assertSessionHasErrors('motivo');
        $this->assertDatabaseCount('ausencias', 1);
        $this->assertDatabaseCount('eventos_ausencias', 0);
    }

    public function test_fichaje_real_impide_la_anulacion_y_no_se_modifica(): void
    {
        $ausencia = Ausencia::create(['user_id' => $this->persona->id, 'absence_date' => '2026-11-06', 'tipo' => 'permiso']);
        $jornada = Jornada::create(['user_id' => $this->persona->id, 'work_date' => '2026-11-06']);
        Tramo::create(['jornada_id' => $jornada->id, 'tipo' => 'trabajo', 'started_at' => '2026-11-06 09:00']);
        $this->actingAs($this->jefe)->post(route('equipo.ausencia.anular', [$this->persona, $ausencia]), ['motivo' => 'Corregir fecha'])->assertSessionHas('aviso');
        $this->assertDatabaseCount('ausencias', 1);
        $this->assertDatabaseCount('tramos', 1);
        $this->assertDatabaseCount('eventos_ausencias', 0);
    }

    public function test_anulacion_no_inventa_vinculo_ni_modifica_solicitud_aprobada(): void
    {
        $solicitud = SolicitudVacaciones::create(['user_id' => $this->persona->id, 'starts_on' => '2026-11-06', 'ends_on' => '2026-11-06', 'estado' => 'aprobada']);
        $ausencia = Ausencia::create(['user_id' => $this->persona->id, 'absence_date' => '2026-11-06', 'tipo' => 'vacaciones']);
        $this->actingAs($this->jefe)->post(route('equipo.ausencia.anular', [$this->persona, $ausencia]), ['motivo' => 'Corrección puntual'])->assertSessionHas('ok');
        $this->assertSame('aprobada', $solicitud->fresh()->estado);
        $this->assertDatabaseHas('eventos_ausencias', ['ausencia_original_id' => $ausencia->id, 'tipo' => 'vacaciones', 'carga_dia_id' => null]);
    }

    public function test_sin_calendario_confirmado_2028_no_se_solicitan_ni_se_aprueban_vacaciones(): void
    {
        $this->travelTo(Carbon::parse('2027-12-20 10:00:00', 'Europe/Madrid'));
        AjusteSaldoVacaciones::create(['user_id' => $this->persona->id, 'actor_id' => $this->jefe->id, 'anio' => 2028,
            'saldo_deseado' => 22, 'aprobados_al_ajuste' => 0, 'motivo' => 'Saldo de prueba', 'operacion' => (string) Str::uuid(), 'created_at' => now()]);
        $this->actingAs($this->persona)->post(route('calendario.vacaciones'), ['desde' => '2028-01-03', 'hasta' => '2028-01-03'])
            ->assertSessionHas('aviso', fn ($texto) => str_contains($texto, 'calendario laboral'));
        $this->assertDatabaseCount('solicitudes_vacaciones', 0);
        $this->actingAs($this->jefe)->post(route('equipo.ausencia', $this->persona), ['absence_date' => '2028-01-03', 'tipo' => 'vacaciones'])
            ->assertSessionHas('aviso', fn ($texto) => str_contains($texto, 'calendario laboral'));
        $solicitud = SolicitudVacaciones::create(['user_id' => $this->persona->id, 'starts_on' => '2028-01-03', 'ends_on' => '2028-01-03', 'estado' => 'pendiente']);
        $this->post(route('jefe.vacaciones.decidir', $solicitud), ['decision' => 'aprobada'])
            ->assertSessionHas('aviso', fn ($texto) => str_contains($texto, 'calendario laboral'));
        $this->assertSame('pendiente', $solicitud->fresh()->estado);
        $this->assertDatabaseCount('ausencias', 0);
    }

    public function test_dia_anulado_de_solicitud_aprobada_puede_pedirse_de_nuevo_sin_ocultar_historial(): void
    {
        AjusteSaldoVacaciones::create(['user_id' => $this->persona->id, 'actor_id' => $this->jefe->id, 'anio' => 2026,
            'saldo_deseado' => 21, 'aprobados_al_ajuste' => 1, 'motivo' => 'Saldo de prueba', 'operacion' => (string) Str::uuid(), 'created_at' => now()]);
        $solicitud = SolicitudVacaciones::create(['user_id' => $this->persona->id, 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-10', 'estado' => 'aprobada']);
        $ausencia = Ausencia::create(['user_id' => $this->persona->id, 'absence_date' => '2026-11-10', 'tipo' => 'vacaciones']);
        $this->actingAs($this->jefe)->post(route('equipo.ausencia.anular', [$this->persona, $ausencia]), ['motivo' => 'Fecha incorrecta'])->assertSessionHas('ok');
        $this->actingAs($this->persona)->post(route('calendario.vacaciones'), ['desde' => '2026-11-10', 'hasta' => '2026-11-10'])->assertSessionHas('ok');
        $this->post(route('calendario.vacaciones'), ['desde' => '2026-11-10', 'hasta' => '2026-11-10'])->assertSessionHas('aviso');
        $this->assertSame('aprobada', $solicitud->fresh()->estado);
        $this->assertSame(1, SolicitudVacaciones::query()->where('estado', 'pendiente')->count());
        $this->assertDatabaseCount('eventos_ausencias', 1);
    }

    public function test_vacaciones_y_ausencias_fuera_de_vigencia_laboral_se_rechazan(): void
    {
        $this->persona->update(['starts_on' => '2026-11-07', 'ends_on' => '2026-11-20']);
        foreach (['2026-11-06', '2026-11-23'] as $fecha) {
            $this->actingAs($this->persona)->post(route('calendario.vacaciones'), ['desde' => $fecha, 'hasta' => $fecha])
                ->assertSessionHas('aviso', fn ($texto) => str_contains($texto, 'periodo laboral'));
            $this->actingAs($this->jefe)->post(route('equipo.ausencia', $this->persona), ['absence_date' => $fecha, 'tipo' => 'permiso'])
                ->assertSessionHas('aviso', fn ($texto) => str_contains($texto, 'periodo laboral'));
            $solicitud = SolicitudVacaciones::create(['user_id' => $this->persona->id, 'starts_on' => $fecha, 'ends_on' => $fecha, 'estado' => 'pendiente']);
            $this->post(route('jefe.vacaciones.decidir', $solicitud), ['decision' => 'aprobada'])
                ->assertSessionHas('aviso', fn ($texto) => str_contains($texto, 'periodo laboral'));
            $this->assertSame('pendiente', $solicitud->fresh()->estado);
        }
        $this->assertDatabaseCount('ausencias', 0);
    }

    public function test_ausencia_historica_permite_registro_con_saldo_desconocido_y_advierte_revision(): void
    {
        $this->actingAs($this->jefe)->post(route('equipo.ausencia', $this->persona), ['absence_date' => '2026-11-05', 'tipo' => 'vacaciones'])
            ->assertSessionHas('ok', fn ($texto) => str_contains($texto, 'Revisa y confirma'));
        $this->post(route('equipo.ausencia', $this->persona), ['absence_date' => '2026-11-10', 'tipo' => 'vacaciones'])
            ->assertSessionHas('aviso', fn ($texto) => str_contains($texto, 'saldo'));
        $this->assertDatabaseCount('ausencias', 1);
    }

    public function test_no_se_asignan_vacaciones_ni_permisos_a_cuenta_tecnica_exclusiva(): void
    {
        foreach (['vacaciones', 'permiso'] as $tipo) {
            $this->actingAs($this->jefe)->post(route('equipo.ausencia', $this->tecnico), ['absence_date' => '2026-11-10', 'tipo' => $tipo])
                ->assertNotFound();
        }
        $this->assertDatabaseCount('ausencias', 0);
        $this->assertDatabaseCount('eventos_ausencias', 0);
    }

    private function cargar(string $fecha): void
    {
        $servicio = app(CargaAdministrativa::class);
        $plan = $servicio->previsualizar($this->tecnico, [$this->persona->id], $fecha, $fecha, $this->horas);
        $servicio->aplicar($this->tecnico, [$this->persona->id], $fecha, $fecha, $this->horas, $plan['hash'], 'Carga de prueba');
    }
}
