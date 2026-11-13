<?php

namespace Tests\Feature;

use App\Models\Centro;
use App\Models\EventoCuenta;
use App\Models\Horario;
use App\Models\InterrupcionPlantilla;
use App\Models\Jornada;
use App\Models\Tramo;
use App\Models\User;
use App\Services\JornadaService;
use App\TipoJornada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class EquipoBajaReactivarTest extends TestCase
{
    use RefreshDatabase;

    private User $jefe;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00', 'Europe/Madrid'));
        $this->calendarioConfirmado(Centro::query()->where('name', 'Madrid')->firstOrFail(), 2026);
        $this->jefe = User::factory()->jefa()->create(['name' => 'Marta Ruiz', 'email' => 'marta.ruiz@abaco.test']);
        $this->ana = User::factory()->create(['name' => 'Ana López', 'email' => 'ana.lopez@abaco.test']);
        Horario::create([
            'user_id' => $this->ana->id, 'tipo' => TipoJornada::Partida->value,
            'morning_start' => '09:00', 'morning_end' => '14:00',
            'afternoon_start' => '15:00', 'afternoon_end' => '18:00',
            'effective_from' => '2026-01-07',
        ]);
    }

    public function test_la_baja_de_hoy_corta_el_acceso_en_la_siguiente_peticion(): void
    {
        $this->actingAs($this->ana->fresh())->get(route('mi-cuenta'))->assertOk();

        $this->actingAs($this->jefe)->from(route('equipo.show', $this->ana))
            ->post(route('equipo.baja', $this->ana), ['fecha_baja' => '2026-11-10', 'motivo_baja' => 'Fin de la relación laboral'])
            ->assertRedirect(route('equipo.show', $this->ana))
            ->assertSessionHas('ok');

        $ana = $this->ana->fresh();
        $this->assertFalse($ana->active);
        $this->assertSame('2026-11-10', $ana->baja_on->toDateString());
        $evento = EventoCuenta::query()->where('user_id', $ana->id)->sole();
        $this->assertSame(EventoCuenta::BAJA, $evento->tipo);
        $this->assertSame($this->jefe->id, $evento->actor_id);
        $this->assertSame('Fin de la relación laboral', $evento->motivo);
        $this->assertSame(['active' => true, 'baja_on' => null], $evento->detalles['antes']);
        $this->assertSame(['active' => false, 'baja_on' => '2026-11-10'], $evento->detalles['despues']);

        $this->actingAs($ana)->get(route('mi-cuenta'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('aviso', 'Tu acceso está cerrado. Tus registros se conservan.');
        $this->assertGuest();
        $this->post(route('login'), ['email' => 'ana.lopez@abaco.test', 'password' => 'password'])
            ->assertSessionHas('aviso', 'Tu acceso está cerrado. Tus registros se conservan.');
        $this->assertGuest();
    }

    public function test_la_baja_futura_mantiene_el_acceso_hasta_ese_dia_y_lo_corta_despues(): void
    {
        $this->actingAs($this->jefe)
            ->post(route('equipo.baja', $this->ana), ['fecha_baja' => '2026-11-20', 'motivo_baja' => 'Termina el proyecto'])
            ->assertSessionHas('ok', 'Baja prevista para el 20/11/2026. Ana López conserva el acceso hasta el final de ese día.');

        $ana = $this->ana->fresh();
        $this->assertTrue($ana->active);
        $this->assertSame('2026-11-20', $ana->baja_on->toDateString());
        $this->actingAs($ana)->get(route('mi-cuenta'))->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-11-20 23:30:00', 'Europe/Madrid'));
        $this->actingAs($this->ana->fresh())->get(route('mi-cuenta'))->assertOk();
        $this->post(route('logout'));
        $this->post(route('login'), ['email' => 'ana.lopez@abaco.test', 'password' => 'password'])
            ->assertRedirect(route('jornada'));
        $this->post(route('logout'));

        Carbon::setTestNow(Carbon::parse('2026-11-21 08:00:00', 'Europe/Madrid'));
        $this->actingAs($this->ana->fresh())->get(route('mi-cuenta'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertFalse($this->ana->fresh()->active, 'La baja cumplida cierra la cuenta para el resto de la aplicación.');
        $this->post(route('login'), ['email' => 'ana.lopez@abaco.test', 'password' => 'password'])
            ->assertSessionHas('aviso', 'Tu acceso está cerrado. Tus registros se conservan.');
        $this->assertGuest();
    }

    public function test_tras_la_baja_no_hay_dias_previstos(): void
    {
        $servicio = app(JornadaService::class);
        $mes = Carbon::parse('2026-11-01');
        $finDeMes = Carbon::parse('2026-11-30 20:00:00', 'Europe/Madrid');
        $sinBaja = $servicio->resumenDelMes($this->ana, $mes, new Collection, $finDeMes)['previstos'];
        $hastaElDiezDeNoviembre = $servicio->resumenDelMes($this->ana, $mes, new Collection, Carbon::parse('2026-11-10 23:00:00', 'Europe/Madrid'))['previstos'];
        $this->assertSame('laborable', $servicio->expectativaDe($this->ana, Carbon::parse('2026-11-12'))['estado']);

        $this->actingAs($this->jefe)
            ->post(route('equipo.baja', $this->ana), ['fecha_baja' => '2026-11-10', 'motivo_baja' => 'Fin de la relación laboral'])
            ->assertSessionHas('ok');

        $ana = $this->ana->fresh();
        $this->assertSame('laborable', $servicio->expectativaDe($ana, Carbon::parse('2026-11-10'))['estado']);
        $this->assertSame('contrato', $servicio->expectativaDe($ana, Carbon::parse('2026-11-11'))['motivo']);
        $this->assertSame('contrato', $servicio->expectativaDe($ana, Carbon::parse('2026-11-12'))['motivo']);
        $conBaja = $servicio->resumenDelMes($ana, $mes, new Collection, $finDeMes)['previstos'];
        $this->assertGreaterThan($hastaElDiezDeNoviembre, $sinBaja);
        $this->assertSame($hastaElDiezDeNoviembre, $conBaja);
    }

    public function test_reincorporar_crea_la_interrupcion_devuelve_el_acceso_y_el_hueco_no_cuenta(): void
    {
        $this->actingAs($this->jefe)
            ->post(route('equipo.baja', $this->ana), ['fecha_baja' => '2026-11-10', 'motivo_baja' => 'Fin de la relación laboral'])
            ->assertSessionHas('ok');

        Carbon::setTestNow(Carbon::parse('2026-11-18 09:00:00', 'Europe/Madrid'));
        $this->actingAs($this->jefe)->get(route('equipo.index', ['bajas' => 1]))
            ->assertOk()->assertSee('Ana López')->assertSee('10/11/2026')->assertSee('Reincorporar');
        $this->get(route('equipo.show', ['user' => $this->ana, 'reincorporar' => 1]))
            ->assertOk()->assertSee('Reincorporar a Ana López')->assertSee('data-abierto', false);

        $this->post(route('equipo.reincorporar', $this->ana), [
            'fecha_reincorporacion' => '2026-11-18', 'motivo_reincorporacion' => 'Nuevo contrato',
        ])->assertRedirect(route('equipo.show', $this->ana))->assertSessionHas('ok');

        $ana = $this->ana->fresh();
        $this->assertTrue($ana->active);
        $this->assertNull($ana->baja_on);
        $hueco = InterrupcionPlantilla::query()->where('user_id', $ana->id)->sole();
        $this->assertSame('2026-11-11', $hueco->desde->toDateString());
        $this->assertSame('2026-11-17', $hueco->hasta->toDateString());
        $evento = EventoCuenta::query()->where('user_id', $ana->id)->where('tipo', EventoCuenta::REINCORPORACION)->sole();
        $this->assertSame($evento->id, $hueco->evento_cuenta_id);
        $this->assertSame(['desde' => '2026-11-11', 'hasta' => '2026-11-17'], $evento->detalles['interrupcion']);
        $this->assertSame('2026-11-10', $evento->detalles['antes']['baja_on']);

        $this->actingAs($ana)->get(route('mi-cuenta'))->assertOk();

        $servicio = app(JornadaService::class);
        $this->assertSame('laborable', $servicio->expectativaDe($ana, Carbon::parse('2026-11-10'))['estado']);
        $this->assertSame('contrato', $servicio->expectativaDe($ana, Carbon::parse('2026-11-12'))['motivo']);
        $this->assertSame('contrato', $servicio->expectativaDe($ana, Carbon::parse('2026-11-17'))['motivo']);
        $this->assertSame('laborable', $servicio->expectativaDe($ana, Carbon::parse('2026-11-18'))['estado']);
        $mes = Carbon::parse('2026-11-01');
        $hastaElDiez = $servicio->resumenDelMes($ana, $mes, new Collection, Carbon::parse('2026-11-10 23:00:00', 'Europe/Madrid'))['previstos'];
        $hastaElDiecisiete = $servicio->resumenDelMes($ana, $mes, new Collection, Carbon::parse('2026-11-17 23:00:00', 'Europe/Madrid'))['previstos'];
        $this->assertSame($hastaElDiez, $hastaElDiecisiete);

        $this->actingAs($this->jefe)->get(route('equipo.show', $ana))
            ->assertOk()
            ->assertSee('Historial de la cuenta')
            ->assertSee('Reincorporación')
            ->assertSee('Sin contrato del 11/11/2026 al 17/11/2026')
            ->assertSee('Nuevo contrato')
            ->assertSee('Dar de baja');
        $this->get(route('equipo.index'))->assertOk()->assertSee('Ana López');
    }

    public function test_reincorporar_al_dia_siguiente_no_crea_interrupcion(): void
    {
        $this->actingAs($this->jefe)
            ->post(route('equipo.baja', $this->ana), ['fecha_baja' => '2026-11-09', 'motivo_baja' => 'Fin de la relación laboral'])
            ->assertSessionHas('ok');

        $this->post(route('equipo.reincorporar', $this->ana), [
            'fecha_reincorporacion' => '2026-11-10', 'motivo_reincorporacion' => 'Error al registrar la baja',
        ])->assertSessionHas('ok');

        $this->assertTrue($this->ana->fresh()->active);
        $this->assertDatabaseCount('interrupciones_plantilla', 0);
    }

    public function test_la_baja_prevista_se_puede_anular(): void
    {
        $this->actingAs($this->jefe)
            ->post(route('equipo.baja', $this->ana), ['fecha_baja' => '2026-11-30', 'motivo_baja' => 'Termina el proyecto'])
            ->assertSessionHas('ok');
        $this->get(route('equipo.index'))->assertOk()->assertSee('Baja prevista: último día el 30/11/2026.');
        $this->get(route('equipo.show', $this->ana))->assertOk()->assertSee('Anular baja prevista')->assertDontSee('Confirmar baja');

        $this->actingAs($this->jefe)->from(route('equipo.show', $this->ana))
            ->post(route('equipo.baja', $this->ana), ['fecha_baja' => '2026-12-15', 'motivo_baja' => 'Otra fecha'])
            ->assertSessionHas('aviso', 'Ya tiene una baja prevista para el 30/11/2026. Anúlala antes de elegir otra fecha.');

        $this->post(route('equipo.reincorporar', $this->ana), ['motivo_reincorporacion' => 'Se amplía el proyecto'])
            ->assertSessionHas('ok', 'Baja prevista anulada. Ana López sigue en el equipo.');

        $ana = $this->ana->fresh();
        $this->assertTrue($ana->active);
        $this->assertNull($ana->baja_on);
        $this->assertDatabaseCount('interrupciones_plantilla', 0);
        $evento = EventoCuenta::query()->where('user_id', $ana->id)->latest('id')->first();
        $this->assertSame(EventoCuenta::REINCORPORACION, $evento->tipo);
        $this->assertTrue($evento->detalles['anula_baja_programada']);
        $this->assertSame('Baja prevista anulada', $evento->titulo());
    }

    public function test_la_baja_de_hoy_se_puede_anular_el_mismo_dia(): void
    {
        $this->actingAs($this->jefe)->from(route('equipo.show', $this->ana))
            ->post(route('equipo.baja', $this->ana), ['fecha_baja' => '2026-11-10', 'motivo_baja' => 'Error al pulsar'])
            ->assertSessionHas('ok', 'Baja registrada con fecha 10/11/2026. El acceso de Ana López queda cerrado y su registro se conserva. Hasta el final del día puedes anularla.');
        $this->assertFalse($this->ana->fresh()->active);

        $this->get(route('equipo.show', $this->ana))->assertOk()
            ->assertSee('Hasta el final del día puedes anularla')
            ->assertSee('data-dialogo="anular-baja"', false)
            ->assertDontSee('data-dialogo="reincorporar"', false)
            ->assertDontSee('data-min="2026-11-11"', false);
        $this->get(route('equipo.index', ['bajas' => 1]))->assertOk()->assertSee('Anular baja');

        $this->from(route('equipo.show', $this->ana))
            ->post(route('equipo.reincorporar', $this->ana), ['motivo_reincorporacion' => 'La baja era de otra persona'])
            ->assertRedirect(route('equipo.show', $this->ana))
            ->assertSessionHas('ok', 'Baja anulada. Ana López vuelve a tener acceso y sigue en el equipo.');

        $ana = $this->ana->fresh();
        $this->assertTrue($ana->active);
        $this->assertNull($ana->baja_on);
        $this->assertDatabaseCount('interrupciones_plantilla', 0);
        $evento = EventoCuenta::query()->where('user_id', $ana->id)->latest('id')->first();
        $this->assertTrue($evento->detalles['anula_baja_programada']);
        $this->assertSame(['active' => false, 'baja_on' => '2026-11-10'], $evento->detalles['antes']);
        $this->assertSame('Baja anulada', $evento->titulo());
        $this->assertSame(['Se anula la baja del 10/11/2026: vuelve a tener acceso.'], $evento->cambios());
        $this->assertSame('laborable', app(JornadaService::class)->expectativaDe($ana, Carbon::parse('2026-11-11'))['estado']);

        $this->actingAs($ana)->get(route('mi-cuenta'))->assertOk();
    }

    public function test_la_baja_de_ayer_ya_no_se_anula_y_pide_reincorporar(): void
    {
        $this->actingAs($this->jefe)
            ->post(route('equipo.baja', $this->ana), ['fecha_baja' => '2026-11-09', 'motivo_baja' => 'Fin de la relación laboral'])
            ->assertSessionHas('ok');

        $this->from(route('equipo.show', $this->ana))
            ->post(route('equipo.reincorporar', $this->ana), ['motivo_reincorporacion' => 'Sin fecha'])
            ->assertSessionHasErrors('fecha_reincorporacion');
        $this->assertFalse($this->ana->fresh()->active);
    }

    public function test_la_baja_con_un_tramo_abierto_no_cierra_el_acceso(): void
    {
        $jornada = Jornada::create(['user_id' => $this->ana->id, 'work_date' => '2026-11-10']);
        $tramo = Tramo::create(['jornada_id' => $jornada->id, 'tipo' => Tramo::TRABAJO, 'started_at' => '2026-11-10 09:00:00']);

        $this->actingAs($this->jefe)->from(route('equipo.show', $this->ana))
            ->post(route('equipo.baja', $this->ana), ['fecha_baja' => '2026-11-10', 'motivo_baja' => 'Fin de la relación laboral'])
            ->assertRedirect(route('equipo.show', $this->ana))
            ->assertSessionHas('aviso', 'Ana López tiene un tramo sin cerrar el 10/11/2026. Tiene que cerrarlo antes de la baja: después ya no podrá entrar a hacerlo.');

        $ana = $this->ana->fresh();
        $this->assertTrue($ana->active);
        $this->assertNull($ana->baja_on);
        $this->assertNull($tramo->fresh()->ended_at);
        $this->assertDatabaseCount('eventos_cuenta', 0);

        // Un tramo olvidado de un día pasado también bloquea una baja con fecha posterior.
        $tramo->update(['ended_at' => '2026-11-10 09:30:00']);
        $pasada = Jornada::create(['user_id' => $this->ana->id, 'work_date' => '2026-11-04']);
        Tramo::create(['jornada_id' => $pasada->id, 'tipo' => Tramo::TRABAJO, 'started_at' => '2026-11-04 09:00:00']);
        $this->post(route('equipo.baja', $this->ana), ['fecha_baja' => '2026-11-10', 'motivo_baja' => 'Fin de la relación laboral'])
            ->assertSessionHas('aviso', 'Ana López tiene un tramo sin cerrar el 04/11/2026. Tiene que cerrarlo antes de la baja: después ya no podrá entrar a hacerlo.');

        // Una baja futura no cierra el acceso: puede cerrar el tramo antes de irse.
        $this->post(route('equipo.baja', $this->ana), ['fecha_baja' => '2026-11-20', 'motivo_baja' => 'Termina el proyecto'])
            ->assertSessionHas('ok');
        $this->assertTrue($this->ana->fresh()->active);
    }

    public function test_ampliar_un_contrato_vencido_no_reescribe_los_dias_pasados(): void
    {
        $this->ana->update(['ends_on' => '2026-10-31']);
        $servicio = app(JornadaService::class);
        $this->assertSame('contrato', $servicio->expectativaDe($this->ana->fresh(), Carbon::parse('2026-11-04'))['motivo']);

        $this->actingAs($this->jefe)->get(route('equipo.edit', $this->ana))->assertOk()
            ->assertSee('El contrato terminó el 31/10/2026. Si lo amplías, los días entre el 01/11/2026 y ayer quedan sin contrato');

        $this->patch(route('equipo.update', $this->ana), ['name' => 'Ana López', 'ends_on' => '2027-06-30', 'motivo' => 'Renovación del contrato'])
            ->assertRedirect(route('equipo.show', $this->ana))
            ->assertSessionHas('ok', 'Alta corregida. El cambio queda en el historial de la cuenta. Del 01/11/2026 al 09/11/2026 no tenía contrato: esos días no cuentan como previstos.');

        $ana = $this->ana->fresh();
        $this->assertSame('2027-06-30', $ana->ends_on->toDateString());
        $hueco = InterrupcionPlantilla::query()->where('user_id', $ana->id)->sole();
        $this->assertSame('2026-11-01', $hueco->desde->toDateString());
        $this->assertSame('2026-11-09', $hueco->hasta->toDateString());
        $evento = EventoCuenta::query()->where('user_id', $ana->id)->sole();
        $this->assertSame($evento->id, $hueco->evento_cuenta_id);
        $this->assertSame(['desde' => '2026-11-01', 'hasta' => '2026-11-09'], $evento->detalles['interrupcion']);

        $this->assertSame('contrato', $servicio->expectativaDe($ana, Carbon::parse('2026-11-04'))['motivo']);
        $this->assertSame('contrato', $servicio->expectativaDe($ana, Carbon::parse('2026-11-09'))['motivo']);
        $this->assertSame('laborable', $servicio->expectativaDe($ana, Carbon::parse('2026-11-10'))['estado']);
        $this->assertSame('laborable', $servicio->expectativaDe($ana, Carbon::parse('2026-10-30'))['estado']);

        $this->get(route('equipo.show', $ana))->assertOk()
            ->assertSee('Fin de contrato: 31/10/2026 → 30/06/2027.')
            ->assertSee('Sin contrato del 01/11/2026 al 09/11/2026: esos días no cuentan como previstos.');
    }

    public function test_ampliar_el_contrato_tras_reincorporar_no_duplica_el_hueco(): void
    {
        $this->ana->update(['ends_on' => '2026-10-31']);
        $this->actingAs($this->jefe)
            ->post(route('equipo.baja', $this->ana), ['fecha_baja' => '2026-10-31', 'motivo_baja' => 'Fin del contrato temporal'])
            ->assertSessionHas('ok');
        $this->post(route('equipo.reincorporar', $this->ana), ['fecha_reincorporacion' => '2026-11-10', 'motivo_reincorporacion' => 'Nuevo contrato'])
            ->assertSessionHas('ok', 'Ana López vuelve al equipo desde el 10/11/2026 y puede entrar otra vez. Los días de baja no cuentan como previstos. Su fin de contrato era el 31/10/2026: corrígelo en Editar alta.');

        $this->patch(route('equipo.update', $this->ana), ['name' => 'Ana López', 'ends_on' => '', 'motivo' => 'Contrato indefinido'])
            ->assertSessionHas('ok', 'Alta corregida. El cambio queda en el historial de la cuenta.');

        $hueco = InterrupcionPlantilla::query()->where('user_id', $this->ana->id)->sole();
        $this->assertSame('2026-11-01', $hueco->desde->toDateString());
        $this->assertSame('2026-11-09', $hueco->hasta->toDateString());
        $this->assertSame('laborable', app(JornadaService::class)->expectativaDe($this->ana->fresh(), Carbon::parse('2026-11-10'))['estado']);
    }

    public function test_una_baja_antigua_sin_fecha_recibe_su_fecha(): void
    {
        $antigua = User::factory()->create(['name' => 'Luis Gómez', 'active' => false]);
        Horario::create([
            'user_id' => $antigua->id, 'tipo' => TipoJornada::Partida->value,
            'morning_start' => '09:00', 'morning_end' => '14:00',
            'afternoon_start' => '15:00', 'afternoon_end' => '18:00',
            'effective_from' => '2026-01-07',
        ]);
        $servicio = app(JornadaService::class);
        $this->assertSame('laborable', $servicio->expectativaDe($antigua, Carbon::parse('2026-11-05'))['estado']);

        $this->actingAs($this->jefe)->get(route('equipo.show', $antigua))->assertOk()
            ->assertSee('De baja sin fecha registrada')
            ->assertSee('Poner fecha a la baja')
            ->assertSee('data-max="2026-11-10"', false)
            ->assertDontSee('data-max="2027-11-10"', false);

        $this->from(route('equipo.show', $antigua))
            ->post(route('equipo.baja', $antigua), ['fecha_baja' => '2026-11-20', 'motivo_baja' => 'Fecha del cese'])
            ->assertSessionHasErrors(['fecha_baja' => 'La cuenta ya está cerrada: el último día tiene que ser hoy o un día pasado.']);

        $this->post(route('equipo.baja', $antigua), ['fecha_baja' => '2026-10-01', 'motivo_baja' => 'Fecha del cese según la carta'])
            ->assertSessionHas('ok', 'Fecha de baja registrada: último día el 01/10/2026. Los días siguientes no cuentan como previstos.');

        $antigua = $antigua->fresh();
        $this->assertFalse($antigua->active);
        $this->assertSame('2026-10-01', $antigua->baja_on->toDateString());
        $this->assertSame('contrato', $servicio->expectativaDe($antigua, Carbon::parse('2026-11-05'))['motivo']);
        $this->assertSame('laborable', $servicio->expectativaDe($antigua, Carbon::parse('2026-10-01'))['estado']);
        $evento = EventoCuenta::query()->where('user_id', $antigua->id)->sole();
        $this->assertSame(EventoCuenta::BAJA, $evento->tipo);
        $this->assertSame(['active' => false, 'baja_on' => null], $evento->detalles['antes']);
        $this->assertSame(['active' => false, 'baja_on' => '2026-10-01'], $evento->detalles['despues']);
        $this->assertSame('Fecha de baja registrada', $evento->titulo());

        $this->get(route('equipo.show', $antigua))->assertOk()
            ->assertSee('Fecha de baja registrada')
            ->assertDontSee('Poner fecha a la baja');
        $this->post(route('equipo.baja', $antigua), ['fecha_baja' => '2026-09-01', 'motivo_baja' => 'Otra fecha'])
            ->assertSessionHas('aviso', 'Luis Gómez ya está de baja.');
    }

    public function test_la_reincorporacion_con_fecha_pasada_avisa_de_los_dias_previstos(): void
    {
        $this->actingAs($this->jefe)
            ->post(route('equipo.baja', $this->ana), ['fecha_baja' => '2026-11-03', 'motivo_baja' => 'Fin de la relación laboral'])
            ->assertSessionHas('ok');
        $this->get(route('equipo.show', $this->ana))->assertOk()
            ->assertSee('Si eliges un día anterior a hoy, los días laborables desde la vuelta hasta ayer cuentan como previstos');

        $this->post(route('equipo.reincorporar', $this->ana), ['fecha_reincorporacion' => '2026-11-05', 'motivo_reincorporacion' => 'Vuelve antes de lo previsto'])
            ->assertSessionHas('ok', 'Ana López vuelve al equipo desde el 05/11/2026 y puede entrar otra vez. Los días de baja no cuentan como previstos. Los días laborables del 05/11/2026 al 09/11/2026 cuentan como previstos aunque no pudo fichar en ellos.');

        $servicio = app(JornadaService::class);
        $ana = $this->ana->fresh();
        $this->assertSame('contrato', $servicio->expectativaDe($ana, Carbon::parse('2026-11-04'))['motivo']);
        $this->assertSame('laborable', $servicio->expectativaDe($ana, Carbon::parse('2026-11-05'))['estado']);
    }

    public function test_el_listado_separa_activas_y_bajas(): void
    {
        $tecnico = User::factory()->soloTecnico()->create(['name' => 'Soporte técnico', 'active' => false]);
        $antigua = User::factory()->create(['name' => 'Baja sin fecha', 'active' => false]);
        $this->ana->update(['active' => false, 'baja_on' => '2026-11-10']);

        $this->actingAs($this->jefe)->get(route('equipo.index'))
            ->assertOk()
            ->assertSee('Marta Ruiz')
            ->assertDontSee('Ana López')
            ->assertDontSee('Baja sin fecha')
            ->assertSee('Bajas (2)')
            ->assertSee(route('equipo.index', ['bajas' => 1]), false)
            ->assertViewHas('totalPersonas', 1);

        $this->get(route('equipo.index', ['bajas' => 1]))
            ->assertOk()
            ->assertSee('Ana López')
            ->assertSee('Baja sin fecha')
            ->assertSee('Último día en la empresa')
            ->assertSee('10/11/2026')
            ->assertSee('Sin fecha registrada')
            ->assertDontSee('Soporte técnico')
            ->assertSee('Volver al equipo')
            ->assertViewHas('personas', fn ($personas) => $personas->pluck('id')->sort()->values()->all() === collect([$this->ana->id, $antigua->id])->sort()->values()->all());

        $this->assertFalse($tecnico->fresh()->active);
    }

    public function test_la_baja_sin_fecha_anterior_se_reincorpora_sin_interrupcion(): void
    {
        $antigua = User::factory()->create(['name' => 'Baja sin fecha', 'active' => false]);

        $this->actingAs($this->jefe)->post(route('equipo.reincorporar', $antigua), [
            'fecha_reincorporacion' => '2026-11-10', 'motivo_reincorporacion' => 'Vuelve al equipo',
        ])->assertSessionHas('ok');

        $this->assertTrue($antigua->fresh()->active);
        $this->assertDatabaseCount('interrupciones_plantilla', 0);
        $this->get(route('equipo.show', $antigua))->assertOk()
            ->assertSee('La baja no tenía fecha registrada: los días anteriores se quedan como estaban.');
    }

    public function test_la_cuenta_tecnica_responde_404(): void
    {
        $tecnico = User::factory()->soloTecnico()->create();

        $this->actingAs($this->jefe)
            ->post(route('equipo.baja', $tecnico), ['fecha_baja' => '2026-11-10', 'motivo_baja' => 'Fin de la relación laboral'])
            ->assertNotFound();
        $this->post(route('equipo.reincorporar', $tecnico), ['fecha_reincorporacion' => '2026-11-10', 'motivo_reincorporacion' => 'Vuelve'])
            ->assertNotFound();
        $this->get(route('equipo.edit', $tecnico))->assertNotFound();
        $this->patch(route('equipo.update', $tecnico), ['name' => 'Otro nombre', 'motivo' => 'Corrección'])->assertNotFound();

        $this->assertTrue($tecnico->fresh()->active);
        $this->assertNull($tecnico->fresh()->baja_on);
        $this->assertDatabaseCount('eventos_cuenta', 0);
    }

    public function test_un_trabajador_no_puede_dar_de_baja_reincorporar_ni_editar(): void
    {
        $luis = User::factory()->create(['name' => 'Luis Gómez']);

        $this->actingAs($luis)
            ->post(route('equipo.baja', $this->ana), ['fecha_baja' => '2026-11-10', 'motivo_baja' => 'Fin de la relación laboral'])
            ->assertRedirect(route('jornada'));
        $this->post(route('equipo.reincorporar', $this->ana), ['fecha_reincorporacion' => '2026-11-10', 'motivo_reincorporacion' => 'Vuelve'])
            ->assertRedirect(route('jornada'));
        $this->get(route('equipo.edit', $this->ana))->assertRedirect(route('jornada'));
        $this->patch(route('equipo.update', $this->ana), ['name' => 'Otro nombre', 'motivo' => 'Corrección'])
            ->assertRedirect(route('jornada'));

        $ana = $this->ana->fresh();
        $this->assertTrue($ana->active);
        $this->assertNull($ana->baja_on);
        $this->assertSame('Ana López', $ana->name);
        $this->assertDatabaseCount('eventos_cuenta', 0);
    }

    public function test_el_responsable_no_se_da_de_baja_a_si_mismo(): void
    {
        $this->actingAs($this->jefe)->from(route('equipo.show', $this->jefe))
            ->post(route('equipo.baja', $this->jefe), ['fecha_baja' => '2026-11-10', 'motivo_baja' => 'Me voy de la empresa'])
            ->assertRedirect(route('equipo.show', $this->jefe))
            ->assertSessionHas('aviso', 'No puedes darte de baja a ti mismo.');

        $this->assertTrue($this->jefe->fresh()->active);
        $this->assertNull($this->jefe->fresh()->baja_on);
        $this->get(route('equipo.show', $this->jefe))->assertOk()
            ->assertSee('Tu propia baja la gestiona otro responsable.')
            ->assertDontSee('Confirmar baja');
    }

    public function test_editar_el_nombre_y_el_fin_de_contrato_deja_evento_y_no_toca_lo_demas(): void
    {
        $sedeAntes = $this->ana->centro_id;

        $this->actingAs($this->jefe)->get(route('equipo.edit', $this->ana))
            ->assertOk()
            ->assertSee('Lo cambia la propia persona desde Mi cuenta', false)
            ->assertSee('Tiene su propio formulario en la ficha.', false)
            ->assertSee('name="ends_on"', false)
            ->assertDontSee('name="email"', false)
            ->assertDontSee('name="starts_on"', false)
            ->assertDontSee('name="centro"', false);

        $this->patch(route('equipo.update', $this->ana), [
            'name' => 'Ana López Martín',
            'ends_on' => '2027-06-30',
            'motivo' => 'Segundo apellido y fin del contrato temporal',
            'email' => 'otra@abaco.test',
            'starts_on' => '2026-11-01',
            'centro' => 'Barcelona',
            'tipo' => TipoJornada::Continua->value,
        ])->assertRedirect(route('equipo.show', $this->ana))->assertSessionHas('ok');

        $ana = $this->ana->fresh();
        $this->assertSame('Ana López Martín', $ana->name);
        $this->assertSame('2027-06-30', $ana->ends_on->toDateString());
        $this->assertSame('ana.lopez@abaco.test', $ana->email);
        $this->assertSame('2026-01-07', $ana->starts_on->toDateString());
        $this->assertSame($sedeAntes, $ana->centro_id);
        $this->assertSame(1, Horario::query()->where('user_id', $ana->id)->count());
        $this->assertSame(TipoJornada::Partida->value, Horario::query()->where('user_id', $ana->id)->value('tipo'));

        $evento = EventoCuenta::query()->where('user_id', $ana->id)->sole();
        $this->assertSame(EventoCuenta::EDICION, $evento->tipo);
        $this->assertSame(['name' => 'Ana López', 'ends_on' => null], $evento->detalles['antes']);
        $this->assertSame(['name' => 'Ana López Martín', 'ends_on' => '2027-06-30'], $evento->detalles['despues']);

        $this->get(route('equipo.show', $ana))->assertOk()
            ->assertSee('Edición del alta')
            ->assertSee('Nombre: Ana López → Ana López Martín.')
            ->assertSee('Fin de contrato: sin fecha → 30/06/2027.');

        $this->patch(route('equipo.update', $ana), ['name' => 'Ana López Martín', 'ends_on' => '', 'motivo' => 'Pasa a indefinido'])
            ->assertSessionHas('ok');
        $this->assertNull($ana->fresh()->ends_on);
        $this->assertSame(2, EventoCuenta::query()->where('user_id', $ana->id)->count());
    }

    public function test_validaciones_de_la_baja(): void
    {
        $this->actingAs($this->jefe)->from(route('equipo.show', $this->ana));

        $this->post(route('equipo.baja', $this->ana), ['fecha_baja' => '2026-11-10'])
            ->assertSessionHasErrors('motivo_baja');
        $this->post(route('equipo.baja', $this->ana), ['fecha_baja' => '2026-11-10', 'motivo_baja' => 'ab'])
            ->assertSessionHasErrors('motivo_baja');
        $this->post(route('equipo.baja', $this->ana), ['fecha_baja' => '2026-11-10', 'motivo_baja' => str_repeat('a', 301)])
            ->assertSessionHasErrors('motivo_baja');
        $this->post(route('equipo.baja', $this->ana), ['motivo_baja' => 'Fin de la relación laboral'])
            ->assertSessionHasErrors('fecha_baja');
        $this->post(route('equipo.baja', $this->ana), ['fecha_baja' => '2026-01-06', 'motivo_baja' => 'Fin de la relación laboral'])
            ->assertSessionHasErrors(['fecha_baja' => 'La baja no puede ser anterior al alta.']);
        $this->post(route('equipo.baja', $this->ana), ['fecha_baja' => '2027-11-11', 'motivo_baja' => 'Fin de la relación laboral'])
            ->assertSessionHasErrors('fecha_baja');
        $this->post(route('equipo.baja', $this->ana), ['fecha_baja' => '10/11/2026', 'motivo_baja' => 'Fin de la relación laboral'])
            ->assertSessionHasErrors('fecha_baja');

        $jornada = Jornada::create(['user_id' => $this->ana->id, 'work_date' => '2026-11-06']);
        Tramo::create(['jornada_id' => $jornada->id, 'tipo' => Tramo::TRABAJO, 'started_at' => '2026-11-06 09:00:00', 'ended_at' => '2026-11-06 14:00:00']);
        $this->post(route('equipo.baja', $this->ana), ['fecha_baja' => '2026-11-05', 'motivo_baja' => 'Fin de la relación laboral'])
            ->assertSessionHas('aviso', 'Hay registro después de esa fecha (último día: 06/11/2026). La baja no puede ser anterior.');

        $ana = $this->ana->fresh();
        $this->assertTrue($ana->active);
        $this->assertNull($ana->baja_on);
        $this->assertDatabaseCount('eventos_cuenta', 0);

        $this->post(route('equipo.baja', $this->ana), ['fecha_baja' => '2026-11-06', 'motivo_baja' => 'Fin de la relación laboral'])
            ->assertSessionHas('ok');
        $this->post(route('equipo.baja', $this->ana), ['fecha_baja' => '2026-11-07', 'motivo_baja' => 'Otra vez'])
            ->assertSessionHas('aviso', 'Ana López ya está de baja.');
        $this->assertDatabaseCount('eventos_cuenta', 1);
    }

    public function test_validaciones_de_la_reincorporacion(): void
    {
        $this->actingAs($this->jefe)->from(route('equipo.show', $this->ana));
        $this->post(route('equipo.reincorporar', $this->ana), ['fecha_reincorporacion' => '2026-11-10', 'motivo_reincorporacion' => 'Vuelve'])
            ->assertSessionHas('aviso', 'Ana López ya está en el equipo.');

        $this->post(route('equipo.baja', $this->ana), ['fecha_baja' => '2026-11-05', 'motivo_baja' => 'Fin de la relación laboral'])
            ->assertSessionHas('ok');

        $this->post(route('equipo.reincorporar', $this->ana), ['fecha_reincorporacion' => '2026-11-05', 'motivo_reincorporacion' => 'Vuelve'])
            ->assertSessionHasErrors(['fecha_reincorporacion' => 'La vuelta tiene que ser posterior al último día de la baja.']);
        $this->post(route('equipo.reincorporar', $this->ana), ['fecha_reincorporacion' => '2026-11-11', 'motivo_reincorporacion' => 'Vuelve'])
            ->assertSessionHasErrors('fecha_reincorporacion');
        $this->post(route('equipo.reincorporar', $this->ana), ['fecha_reincorporacion' => '2026-11-10', 'motivo_reincorporacion' => 'no'])
            ->assertSessionHasErrors('motivo_reincorporacion');
        $this->post(route('equipo.reincorporar', $this->ana), ['motivo_reincorporacion' => 'Vuelve al equipo'])
            ->assertSessionHasErrors('fecha_reincorporacion');

        $ana = $this->ana->fresh();
        $this->assertFalse($ana->active);
        $this->assertSame('2026-11-05', $ana->baja_on->toDateString());
        $this->assertDatabaseCount('interrupciones_plantilla', 0);
    }

    public function test_validaciones_de_la_edicion(): void
    {
        $this->actingAs($this->jefe)->from(route('equipo.edit', $this->ana));

        $this->patch(route('equipo.update', $this->ana), ['name' => 'A', 'motivo' => 'Corrección del nombre'])
            ->assertSessionHasErrors('name');
        $this->patch(route('equipo.update', $this->ana), ['name' => str_repeat('a', 81), 'motivo' => 'Corrección del nombre'])
            ->assertSessionHasErrors('name');
        $this->patch(route('equipo.update', $this->ana), ['name' => 'Ana López', 'ends_on' => '2026-01-06', 'motivo' => 'Fin de contrato'])
            ->assertSessionHasErrors(['ends_on' => 'El fin de contrato no puede ser anterior al alta.']);
        $this->patch(route('equipo.update', $this->ana), ['name' => 'Ana López Martín'])
            ->assertSessionHasErrors('motivo');
        $this->patch(route('equipo.update', $this->ana), ['name' => 'Ana López', 'motivo' => 'Sin cambios reales'])
            ->assertRedirect(route('equipo.edit', $this->ana))
            ->assertSessionHas('aviso', 'No hay cambios que guardar.');

        $jornada = Jornada::create(['user_id' => $this->ana->id, 'work_date' => '2026-11-06']);
        Tramo::create(['jornada_id' => $jornada->id, 'tipo' => Tramo::TRABAJO, 'started_at' => '2026-11-06 09:00:00', 'ended_at' => '2026-11-06 14:00:00']);
        $this->patch(route('equipo.update', $this->ana), ['name' => 'Ana López', 'ends_on' => '2026-11-05', 'motivo' => 'Fin de contrato'])
            ->assertSessionHas('aviso', 'Hay registro después de ese fin de contrato (último día: 06/11/2026). Elige una fecha igual o posterior.');

        $ana = $this->ana->fresh();
        $this->assertSame('Ana López', $ana->name);
        $this->assertNull($ana->ends_on);
        $this->assertDatabaseCount('eventos_cuenta', 0);
    }
}
