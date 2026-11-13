<?php

namespace Tests\Feature;

use App\Mail\TicketResuelto;
use App\Models\AsignacionCentro;
use App\Models\Ausencia;
use App\Models\Aviso;
use App\Models\Centro;
use App\Models\Festivo;
use App\Models\Horario;
use App\Models\Jornada;
use App\Models\Ticket;
use App\Models\Tramo;
use App\Models\User;
use App\Services\AjustarSaldoVacaciones;
use App\Services\CupoVacaciones;
use App\Services\JornadaService;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditoriaFlujosTest extends TestCase
{
    use RefreshDatabase;

    public function test_cambiar_a_otra_sede_no_recalcula_los_festivos_ni_las_horas_del_pasado(): void
    {
        $this->travelTo(Carbon::parse('2026-10-30 12:00:00', 'Europe/Madrid'));
        $madrid = Centro::query()->where('name', 'Madrid')->firstOrFail();
        $segovia = Centro::create(['name' => 'Segovia', 'municipality' => 'Segovia', 'community' => 'Castilla y León']);
        $this->calendarioConfirmado($madrid, 2026);
        $this->calendarioConfirmado($segovia, 2026);
        $jefa = User::factory()->jefa()->create();
        $ana = $this->persona($madrid);
        Festivo::create(['holiday_date' => '2026-01-19', 'name' => 'Festivo anterior de Madrid', 'ambito' => 'pueblo', 'municipality' => 'Madrid', 'centro_id' => $madrid->id]);
        Festivo::create(['holiday_date' => '2026-01-20', 'name' => 'Festivo de Segovia de enero', 'ambito' => 'pueblo', 'municipality' => 'Segovia', 'centro_id' => $segovia->id]);
        Festivo::create(['holiday_date' => '2026-11-09', 'name' => 'Madrid después del traslado', 'ambito' => 'pueblo', 'municipality' => 'Madrid', 'centro_id' => $madrid->id]);
        Festivo::create(['holiday_date' => '2026-11-11', 'name' => 'Segovia después del traslado', 'ambito' => 'pueblo', 'municipality' => 'Segovia', 'centro_id' => $segovia->id]);
        app(AjustarSaldoVacaciones::class)->ajustar($jefa, $ana, 2026, 22, 0, 0, 'Saldo confirmado en prueba', (string) Str::uuid());
        $vacacion = Ausencia::create(['user_id' => $ana->id, 'absence_date' => '2026-01-20', 'tipo' => 'vacaciones']);
        $this->assertSame(21, app(CupoVacaciones::class)->restantes($ana, 2026));
        $jornada = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-01-21']);
        $tramo = Tramo::create(['jornada_id' => $jornada->id, 'tipo' => Tramo::TRABAJO, 'started_at' => '2026-01-21 09:00:00', 'ended_at' => '2026-01-21 18:00:00']);
        $this->assertFalse(app(JornadaService::class)->esDiaEsperado($ana, Carbon::parse('2026-01-19')));

        $this->actingAs($jefa)->post(route('equipo.centro', $ana), ['centro' => 'Segovia'])->assertSessionHas('ok');
        $ana = $ana->fresh();

        $this->assertFalse(app(JornadaService::class)->esDiaEsperado($ana, Carbon::parse('2026-01-19')));
        $calendario = $this->actingAs($ana)->get(route('calendario', ['mes' => 1]))
            ->assertOk()->assertSee('Festivo anterior de Madrid')->assertDontSee('Festivo de Segovia de enero');
        $this->assertSame(['Madrid', 'Segovia'], $calendario->viewData('tramos')->map(fn ($tramo) => $tramo->importacion->municipio)->all());
        $this->assertSame(['2026-10-29', '2026-12-31'], $calendario->viewData('tramos')->map(fn ($tramo) => $tramo->hasta->toDateString())->all());
        $this->get(route('calendario', ['mes' => 11]))->assertOk()->assertSee('Segovia después del traslado')->assertDontSee('Madrid después del traslado');
        $this->assertSame(21, app(CupoVacaciones::class)->restantes($ana, 2026));
        $this->assertSame('Segovia', $ana->sede->municipality);
        $this->assertSame(540, app(JornadaService::class)->minutosRegistrados($ana, $jornada->fresh('tramos')));
        $this->assertModelExists($tramo);
        $this->assertModelExists($vacacion);
        $this->assertSame('2026-01-20', $vacacion->fresh()->absence_date->toDateString());
    }

    public function test_el_registro_ignora_un_filtro_de_fecha_imposible_sin_responder_500(): void
    {
        $this->travelTo(Carbon::parse('2026-10-30 12:00:00', 'Europe/Madrid'));
        $ana = $this->persona(Centro::query()->where('name', 'Madrid')->firstOrFail());

        $this->actingAs($ana)->get(route('registro', ['desde' => '2026-99-99', 'hasta' => '2026-10-30']))
            ->assertOk()->assertViewHas('desde', '');
    }

    public function test_abrir_hoy_no_genera_avisos_caducados_por_meses_sin_fichar_y_conserva_el_historico_existente(): void
    {
        $this->travelTo(Carbon::parse('2026-10-30 12:00:00', 'Europe/Madrid'));
        $centro = Centro::query()->where('name', 'Madrid')->firstOrFail();
        $this->calendarioConfirmado($centro, 2026);
        $ana = $this->persona($centro);
        $jornada = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-01-07']);
        Tramo::create(['jornada_id' => $jornada->id, 'tipo' => Tramo::TRABAJO, 'started_at' => '2026-01-07 09:00:00', 'ended_at' => '2026-01-07 18:00:00']);
        $historico = Aviso::create(['user_id' => $ana->id, 'tipo' => 'inicio', 'work_date' => '2026-02-02', 'cuenta' => true]);

        $this->actingAs($ana)->get(route('jornada'))->assertOk();

        $this->assertDatabaseMissing('avisos', ['user_id' => $ana->id, 'work_date' => '2026-01-08 00:00:00']);
        $this->assertSame(1, Aviso::query()->where('user_id', $ana->id)->whereDate('work_date', '<', '2026-10-23')->count());
        $this->assertModelExists($historico);
        $this->assertSame(5, Aviso::query()->where('user_id', $ana->id)->whereDate('work_date', '>=', '2026-10-23')->whereDate('work_date', '<', '2026-10-30')->count());
    }

    public function test_un_rango_de_registro_invertido_se_limpia_con_un_aviso_y_el_csv_sigue_disponible(): void
    {
        $this->travelTo(Carbon::parse('2026-10-30 12:00:00', 'Europe/Madrid'));
        $ana = $this->persona(Centro::query()->where('name', 'Madrid')->firstOrFail());
        $filtros = ['periodo' => 'todo', 'desde' => '2026-10-30', 'hasta' => '2026-10-01'];

        $this->actingAs($ana)->get(route('registro', $filtros))->assertOk()
            ->assertViewHas('desde', '')->assertViewHas('hasta', '')
            ->assertSee('La fecha de inicio debe ser anterior o igual a la fecha de fin.');
        $this->get(route('registro.csv', $filtros))->assertOk()->assertDownload('registro-'.$ana->id.'.csv');
    }

    public function test_el_cambio_de_sede_rechaza_fechas_retroactivas_o_futuras_sin_tocar_el_historial(): void
    {
        $this->travelTo(Carbon::parse('2026-10-30 12:00:00', 'Europe/Madrid'));
        $madrid = Centro::query()->where('name', 'Madrid')->firstOrFail();
        $segovia = Centro::create(['name' => 'Segovia', 'municipality' => 'Segovia', 'community' => 'Castilla y León']);
        $jefa = User::factory()->jefa()->create();
        $ana = $this->persona($madrid);

        foreach (['2026-10-29', '2026-10-31'] as $fecha) {
            $this->actingAs($jefa)->post(route('equipo.centro', $ana), ['centro' => $segovia->name, 'effective_from' => $fecha])
                ->assertSessionHasErrors('effective_from');
        }

        $this->assertSame($madrid->id, $ana->fresh()->centro_id);
        $this->assertDatabaseCount('asignaciones_centro', 0);
    }

    public function test_cambiar_dos_veces_hoy_conserva_ayer_y_no_duplica_el_ultimo_destino(): void
    {
        $this->travelTo(Carbon::parse('2026-10-30 12:00:00', 'Europe/Madrid'));
        $madrid = Centro::query()->where('name', 'Madrid')->firstOrFail();
        $segovia = Centro::create(['name' => 'Segovia', 'municipality' => 'Segovia', 'community' => 'Castilla y León']);
        $jefa = User::factory()->jefa()->create();
        $ana = $this->persona($madrid);
        $this->actingAs($jefa)->post(route('equipo.centro', $ana), ['centro' => $segovia->name])->assertSessionHas('ok');
        $this->post(route('equipo.centro', $ana), ['centro' => $madrid->name])->assertSessionHas('ok');
        $cantidad = AsignacionCentro::query()->count();

        $this->post(route('equipo.centro', $ana), ['centro' => $madrid->name])->assertSessionHas('ok');

        $ana = $ana->fresh();
        $this->assertSame($madrid->id, $ana->sedeEn(Carbon::parse('2026-10-29'))->id);
        $this->assertSame($madrid->id, $ana->sedeEn(now())->id);
        $this->assertSame(2, $ana->asignacionesCentro->whereNull('cancelado_at')->count());
        $this->assertSame($cantidad, AsignacionCentro::query()->count());
        $this->assertTrue(AsignacionCentro::query()->where('centro_id', $segovia->id)->whereNotNull('cancelado_at')->exists());
        $this->assertDatabaseHas('asignaciones_centro', ['user_id' => $ana->id, 'centro_id' => $madrid->id, 'creado_por' => $jefa->id]);
    }

    public function test_un_trabajador_no_puede_cambiar_sedes_ni_el_historial_de_otras_personas(): void
    {
        $this->travelTo(Carbon::parse('2026-10-30 12:00:00', 'Europe/Madrid'));
        $madrid = Centro::query()->where('name', 'Madrid')->firstOrFail();
        $segovia = Centro::create(['name' => 'Segovia', 'municipality' => 'Segovia', 'community' => 'Castilla y León']);
        $ana = $this->persona($madrid);
        $otra = User::factory()->create();

        $this->actingAs($ana)->post(route('equipo.centro', $otra), ['centro' => $segovia->name])->assertRedirect(route('jornada'));

        $this->assertSame($madrid->id, $otra->fresh()->centro_id);
        $this->assertDatabaseCount('asignaciones_centro', 0);
    }

    public function test_responder_a_un_ticket_resuelto_tras_cargar_la_pagina_lo_reabre_sin_perder_el_mensaje(): void
    {
        Mail::fake();
        $ana = User::factory()->create();
        $ticket = Ticket::factory()->create(['user_id' => $ana->id, 'estado' => Ticket::ABIERTO]);
        $servicio = app(TicketService::class);
        $servicio->cambiarEstado($ticket->fresh(), Ticket::RESUELTO);

        $mensaje = $servicio->responder($ticket, $ana, 'He vuelto a probarlo y sigue fallando.');

        $this->assertSame(Ticket::ABIERTO, $ticket->fresh()->estado);
        $this->assertNull($ticket->fresh()->resuelto_at);
        $this->assertDatabaseHas('ticket_mensajes', ['id' => $mensaje->id, 'ticket_id' => $ticket->id, 'cuerpo' => 'He vuelto a probarlo y sigue fallando.']);
        Mail::assertSent(TicketResuelto::class, 1);
    }

    public function test_sin_demo_explicita_no_se_ofrecen_ni_permiten_borrados_de_prueba_aunque_coincida_el_email(): void
    {
        config(['app.demo' => false]);
        $this->travelTo(Carbon::parse('2026-10-30 12:00:00', 'Europe/Madrid'));
        $ana = User::factory()->create(['email' => 'ana.lopez@abaco.test']);
        foreach (['2026-10-30', '2026-10-29', '2026-10-27'] as $fecha) {
            Jornada::create(['user_id' => $ana->id, 'work_date' => $fecha]);
        }

        $this->actingAs($ana)->get(route('jornada'))->assertOk()
            ->assertViewHas('puedeBorrarPrueba', false)
            ->assertDontSee('Borrar fichaje de hoy')->assertDontSee('Borrar fichaje de ayer');
        foreach (['jornada.borrar-prueba', 'jornada.borrar-ayer', 'jornada.borrar-tres-dias'] as $ruta) {
            $this->post(route($ruta))->assertForbidden();
        }

        $this->assertDatabaseCount('jornadas', 3);
    }

    private function persona(Centro $centro): User
    {
        $persona = User::factory()->create(['centro_id' => $centro->id, 'municipality' => $centro->municipality, 'starts_on' => '2026-01-01']);
        Horario::create(['user_id' => $persona->id, 'morning_start' => '09:00', 'morning_end' => '14:00', 'afternoon_start' => '15:00', 'afternoon_end' => '18:00', 'effective_from' => '2026-01-01']);

        return $persona;
    }
}
