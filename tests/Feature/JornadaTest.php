<?php

namespace Tests\Feature;

use App\Http\Middleware\ProtectFormRequests;
use App\Models\Ausencia;
use App\Models\Aviso;
use App\Models\Centro;
use App\Models\Festivo;
use App\Models\Horario;
use App\Models\ImportacionCalendario;
use App\Models\Incidencia;
use App\Models\Jornada;
use App\Models\SolicitudVacaciones;
use App\Models\Ticket;
use App\Models\Tramo;
use App\Models\User;
use App\Services\AjustarSaldoVacaciones;
use App\Services\CupoVacaciones;
use App\Services\JornadaService;
use App\Services\NagerFestivos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class JornadaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-07 10:45:00', 'Europe/Madrid'));
    }

    public function test_entrada_pausa_salida_y_no_duplica(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);

        $this->actingAs($ana)
            ->post(route('jornada.entrada'))
            ->assertRedirect()
            ->assertSessionHas('ok');

        $this->assertDatabaseCount('jornadas', 1);

        $this->actingAs($ana)
            ->post(route('jornada.entrada'))
            ->assertSessionHas('aviso');

        $this->assertDatabaseCount('jornadas', 1);
        $this->assertSame(1, Tramo::query()->count());

        $this->actingAs($ana)->post(route('jornada.pausa'))->assertSessionHas('ok');
        $this->actingAs($ana)->post(route('jornada.volver'))->assertSessionHas('ok');
        $this->assertSame(2, Tramo::query()->where('tipo', Tramo::TRABAJO)->count());

        Carbon::setTestNow(Carbon::parse('2026-10-07 18:10:00', 'Europe/Madrid'));
        $this->actingAs($ana)->post(route('jornada.salida'))->assertSessionHas('ok');

        $jornada = Jornada::query()->with('tramos')->first();
        $this->assertTrue($jornada->estaCerrada());
        $this->assertNotNull($jornada->tramos->first()->ended_at);
    }

    public function test_empezar_jornada_antes_de_tiempo_pide_confirmacion(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        Carbon::setTestNow(Carbon::parse('2026-10-07 08:40:00', 'Europe/Madrid'));

        $this->actingAs($ana)
            ->get(route('jornada'))
            ->assertOk()
            ->assertSee('Empezar jornada', false)
            ->assertSee('Tu jornada empieza a las 09:00. ¿Empiezas ya?', false)
            ->assertSee('Sí, empiezo', false)
            ->assertSee('Todavía no', false);

        $this->actingAs($ana)
            ->post(route('jornada.entrada'))
            ->assertRedirect()
            ->assertSessionHas('confirma_temprano')
            ->assertSessionMissing('ok');

        $this->assertDatabaseCount('jornadas', 0);

        $this->actingAs($ana)
            ->post(route('jornada.entrada'), ['confirmar_temprano' => '1'])
            ->assertSessionHas('ok', 'Guardado. Entrada a las 08:40.');

        $this->assertSame('08:40', Tramo::query()->first()->started_at->timezone('Europe/Madrid')->format('H:i'));
    }

    public function test_empezar_jornada_en_el_margen_de_diez_minutos_no_pregunta(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        Carbon::setTestNow(Carbon::parse('2026-10-07 08:51:00', 'Europe/Madrid'));

        $this->actingAs($ana)
            ->get(route('jornada'))
            ->assertOk()
            ->assertSee('Empezar jornada', false)
            ->assertDontSee('¿Empiezas ya?', false);

        $this->actingAs($ana)
            ->post(route('jornada.entrada'))
            ->assertSessionHas('ok');

        $this->assertSame('08:51', Tramo::query()->first()->started_at->timezone('Europe/Madrid')->format('H:i'));
    }

    public function test_cierre_olvidado_permite_empezar_el_dia_nuevo(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $jornada = Jornada::create([
            'user_id' => $ana->id,
            'work_date' => '2026-10-06',
        ]);
        Tramo::create([
            'jornada_id' => $jornada->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-06 09:00:00',
        ]);

        $this->actingAs($ana)
            ->get(route('jornada'))
            ->assertSee('No cerraste el martes', false);

        $this->actingAs($ana)
            ->post(route('jornada.entrada'))
            ->assertSessionHas('aviso');

        $this->actingAs($ana)
            ->post(route('jornada.completar'), ['hora' => '18:00'])
            ->assertSessionHas('ok');

        $this->assertTrue($jornada->fresh()->closed_late);
        $this->actingAs($ana)->post(route('jornada.entrada'))->assertSessionHas('ok');
        $this->assertDatabaseCount('jornadas', 2);
    }

    public function test_una_persona_no_ve_el_registro_de_otra(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $lucia = $this->persona('Lucía Vega', 'lucia.vega@abaco.test', 'Alcalá de Henares');
        $jornada = Jornada::create([
            'user_id' => $lucia->id,
            'work_date' => '2026-10-07',
        ]);

        $this->actingAs($ana)
            ->get(route('registro.show', $jornada))
            ->assertForbidden();

        $this->actingAs($ana)
            ->get(route('equipo.index'))
            ->assertRedirect(route('jornada'));
    }

    public function test_la_trabajadora_ve_los_festivos_de_su_centro(): void
    {
        $this->festivosPublicos($this->muestraNager());
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');

        $this->actingAs($ana)
            ->get(route('calendario'))
            ->assertOk()
            ->assertSee('Calendario', false)
            ->assertSee('octubre 2026', false)
            ->assertSee('Mes anterior', false)
            ->assertSee('Mes siguiente', false)
            ->assertSee('mes=9', false)
            ->assertSee('mes=11', false)
            ->assertDontSee('enero', false)
            ->assertDontSee('Año Nuevo', false)
            ->assertDontSee('Festivos públicos del centro', false)
            ->assertDontSee('Si cae en domingo, pasa al lunes.', false)
            ->assertSee('Festivo Nacional', false)
            ->assertSee('Festivo Autonómico', false)
            ->assertDontSee('Jueves Santo', false)
            ->assertDontSee('Festivo Local', false)
            ->assertDontSee('Día de Andalucía', false)
            ->assertDontSee('Solo observancia', false)
            ->assertDontSee('Quién falta', false)
            ->assertDontSee('Fallo común', false)
            ->assertDontSee('Guardar festivo', false);

        $this->actingAs($ana)
            ->get(route('calendario', ['mes' => 1]))
            ->assertOk()
            ->assertSee('enero 2026', false)
            ->assertSee('casilla-estado', false)
            ->assertSee('Año Nuevo', false)
            ->assertDontSee('Jueves Santo', false)
            ->assertDontSee('Permiso', false);

        $this->actingAs($ana)
            ->get(route('calendario', ['mes' => 4]))
            ->assertOk()
            ->assertSee('casilla-comunidad', false)
            ->assertSee('Jueves Santo', false);

        $this->actingAs($ana)
            ->get(route('calendario', ['mes' => 11, 'fragmento' => 1]))
            ->assertOk()
            ->assertSee('noviembre 2026', false)
            ->assertDontSee('Solicitar vacaciones', false)
            ->assertDontSee('Mi registro', false);

        $this->actingAs($ana)
            ->post('/calendario', [
                'holiday_date' => '2026-10-20',
                'name' => 'Inventado',
            ])
            ->assertStatus(405);
    }

    public function test_el_calendario_lista_festivos_y_vacaciones_del_mes(): void
    {
        $this->festivosPublicos([
            [
                'date' => '2026-10-12',
                'localName' => 'Fiesta Nacional de España',
                'global' => true,
                'counties' => null,
                'types' => ['Public'],
            ],
        ]);
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->confirmarSaldoPrueba($ana);
        Ausencia::create([
            'user_id' => $ana->id,
            'absence_date' => '2026-10-20',
            'tipo' => 'vacaciones',
        ]);

        $this->actingAs($ana)->post(route('calendario.vacaciones'), [
            'desde' => '2026-10-21',
            'hasta' => '2026-10-21',
        ])->assertSessionHas('ok');

        $this->actingAs($ana)
            ->get(route('calendario', ['mes' => 10]))
            ->assertOk()
            ->assertSee('Fiesta Nacional de España', false)
            ->assertSee('Festivo Nacional', false)
            ->assertSee('Vacaciones', false)
            ->assertSee('Ausencia prevista', false)
            ->assertSee('Pendiente de decidir', false)
            ->assertSee('casilla-etiqueta-festivo', false)
            ->assertSee('casilla-etiqueta-vacaciones', false)
            ->assertSee('casilla-etiqueta-vacaciones-pendiente', false);
    }

    public function test_la_baja_conserva_la_jornada(): void
    {
        $marta = $this->persona('Marta Ruiz', 'marta.ruiz@abaco.test', 'Madrid', 'jefa');
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        Jornada::create([
            'user_id' => $ana->id,
            'work_date' => '2026-10-06',
        ]);

        $this->actingAs($marta)->post(route('equipo.baja', $ana), [
            'fecha_baja' => '2026-10-07',
            'motivo_baja' => 'Fin de la relación laboral',
        ])->assertSessionHas('ok');

        $this->assertFalse($ana->fresh()->active);
        $this->assertDatabaseHas('jornadas', ['user_id' => $ana->id]);
        $this->actingAsGuest()->post(route('login'), [
            'email' => 'ana.lopez@abaco.test',
            'password' => 'Jornada2026',
        ])->assertSessionHas('aviso');
    }

    public function test_el_festivo_sigue_el_centro_y_no_el_municipio(): void
    {
        $this->festivosPublicos($this->muestraNager());
        $madrid = $this->persona('Nuria Soler', 'nuria.soler@abaco.test', 'Alcalá de Henares');
        $cataluna = $this->persona('Laia Puig', 'laia.puig@abaco.test', 'Madrid');
        $cataluna->update(['centro_id' => Centro::query()->where('name', 'Barcelona')->value('id')]);
        $this->horario($madrid);
        $this->horario($cataluna);

        Carbon::setTestNow(Carbon::parse('2026-04-02 10:30:00', 'Europe/Madrid'));

        $this->actingAs($madrid)
            ->get(route('jornada'))
            ->assertSee('Hoy es festivo en tu centro', false)
            ->assertDontSee('¿Ya estabas trabajando?', false);

        $this->actingAs($cataluna)
            ->get(route('jornada'))
            ->assertDontSee('Hoy es festivo en tu centro', false)
            ->assertSee('¿Cuándo empezaste?', false)
            ->assertDontSee('¿Ya estabas trabajando?', false);
    }

    public function test_un_centro_nuevo_recibe_solo_su_festivo_local(): void
    {
        $getafe = Centro::create([
            'name' => 'Sede Getafe',
            'municipality' => 'Getafe',
            'community' => 'Comunidad de Madrid',
        ]);
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $ana->update([
            'centro_id' => $getafe->id,
            'municipality' => $getafe->municipality,
        ]);
        Festivo::create([
            'holiday_date' => '2026-10-08',
            'name' => 'Fiestas de Getafe',
            'ambito' => 'pueblo',
            'municipality' => 'Getafe',
            'centro_id' => $getafe->id,
        ]);
        Festivo::create([
            'holiday_date' => '2026-10-08',
            'name' => 'Fiestas de Madrid',
            'ambito' => 'pueblo',
            'municipality' => 'Madrid',
            'centro_id' => Centro::query()->where('name', 'Madrid')->value('id'),
        ]);

        $this->actingAs($ana)
            ->get(route('calendario', ['mes' => 10]))
            ->assertSee('Fiestas de Getafe', false)
            ->assertDontSee('Fiestas de Madrid', false);
    }

    public function test_la_reunion_conserva_la_hora_prevista(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);

        $this->actingAs($ana)
            ->get(route('jornada'))
            ->assertSee('Inicio de jornada', false)
            ->assertSee('Empezar jornada', false)
            ->assertDontSee('Trabajé sin el portátil', false)
            ->assertSee('¿Cuándo empezaste?', false)
            ->assertSee('Empiezo ahora', false)
            ->assertSee('Otra hora', false)
            ->assertSee('Estaba en una reunión desde las 09:00', false)
            ->assertDontSee('Empecé a otra hora', false)
            ->assertDontSee('Trabajo fuera del equipo', false)
            ->assertDontSee('Reunión, mantener las 09:00', false);

        $this->actingAs($ana)->post(route('jornada.reunion'))->assertSessionHas('ok');

        $tramo = Tramo::query()->first();
        $this->assertSame('09:00', $tramo->started_at->timezone('Europe/Madrid')->format('H:i'));
        $this->assertSame('10:45', $tramo->anotado_at->timezone('Europe/Madrid')->format('H:i'));
        $this->assertSame('reunion', $tramo->situacion);
    }

    public function test_el_inicio_de_jornada_usa_hora_de_00_a_23(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        $jornada = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-06']);
        Tramo::create([
            'jornada_id' => $jornada->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-06 09:00:00',
        ]);

        $this->actingAs($ana)
            ->get(route('jornada'))
            ->assertSee('No cerraste el martes', false)
            ->assertSee('value="23"', false)
            ->assertDontSee('>AM<', false);

        $this->actingAs($ana)
            ->post(route('jornada.completar'), ['hora' => '18:00'])
            ->assertSessionHas('ok');

        $this->actingAs($ana)
            ->post(route('jornada.entrada'), ['modo' => 'otra', 'hora' => '08:30'])
            ->assertSessionHas('ok');

        $hoy = Tramo::query()->whereDate('started_at', '2026-10-07')->first();
        $this->assertSame('08:30', $hoy->started_at->timezone('Europe/Madrid')->format('H:i'));
    }

    public function test_ana_marta_y_carmen_pueden_borrar_el_fichaje_de_hoy_para_probar(): void
    {
        config(['app.demo' => true]);
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $marta = $this->persona('Marta Ruiz', 'marta.ruiz@abaco.test', 'Madrid', 'jefa');
        $carmen = $this->persona('Carmen Ortega', 'carmen.ortega@abaco.test', 'Madrid', 'tecnico');
        $lucia = $this->persona('Lucía Vega', 'lucia.vega@abaco.test', 'Alcalá de Henares');
        $deAna = Jornada::create([
            'user_id' => $ana->id,
            'work_date' => '2026-10-07',
        ]);
        Tramo::create([
            'jornada_id' => $deAna->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-07 09:00:00',
            'ended_at' => '2026-10-07 11:34:00',
        ]);
        $deMarta = Jornada::create([
            'user_id' => $marta->id,
            'work_date' => '2026-10-07',
        ]);
        $deCarmen = Jornada::create([
            'user_id' => $carmen->id,
            'work_date' => '2026-10-07',
        ]);

        $this->actingAs($lucia)
            ->post(route('jornada.borrar-prueba'))
            ->assertForbidden();

        $this->actingAs($lucia)
            ->post(route('jornada.borrar-ayer'))
            ->assertForbidden();

        $this->actingAs($lucia)
            ->get(route('jornada'))
            ->assertDontSee('Borrar fichaje de hoy', false)
            ->assertDontSee('Borrar fichaje de ayer', false);

        $this->actingAs($marta)
            ->get(route('jornada'))
            ->assertSee('Borrar fichaje de hoy', false)
            ->assertSee('Borrar fichaje de ayer', false);

        $this->actingAs($carmen)
            ->get(route('jornada'))
            ->assertSee('Borrar fichaje de hoy', false);

        $this->actingAs($ana)
            ->post(route('jornada.borrar-prueba'))
            ->assertRedirect();

        $this->assertDatabaseMissing('jornadas', ['id' => $deAna->id]);
        $this->assertDatabaseHas('jornadas', ['id' => $deMarta->id]);

        $this->actingAs($marta)
            ->post(route('jornada.borrar-prueba'))
            ->assertRedirect();

        $this->actingAs($carmen)
            ->post(route('jornada.borrar-prueba'))
            ->assertRedirect();

        $this->assertDatabaseMissing('jornadas', ['id' => $deCarmen->id]);
        $this->assertDatabaseCount('jornadas', 0);
        $this->actingAs($ana)
            ->get(route('jornada'))
            ->assertSee('Inicio de jornada', false);
    }

    public function test_el_equipo_encendido_no_alarga_la_jornada(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        $jornada = Jornada::create([
            'user_id' => $ana->id,
            'work_date' => '2026-10-07',
        ]);
        Tramo::create([
            'jornada_id' => $jornada->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-07 09:00:00',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-10-07 21:00:00', 'Europe/Madrid'));

        $this->actingAs($ana)
            ->get(route('jornada'))
            ->assertSee('Son las 18:00. ¿Terminas ya?', false)
            ->assertSee('9 h 00', false)
            ->assertDontSee('Terminar jornada', false)
            ->assertDontSee('12 h', false);

        $this->actingAs($ana)
            ->post(route('jornada.cerrar'), ['hora' => '18:00'])
            ->assertSessionHas('ok');

        $this->assertSame('18:00', Tramo::query()->first()->ended_at->timezone('Europe/Madrid')->format('H:i'));
    }

    public function test_seguir_guarda_la_salida_real_sin_pedir_autorizacion(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        $jornada = Jornada::create([
            'user_id' => $ana->id,
            'work_date' => '2026-10-07',
        ]);
        Tramo::create([
            'jornada_id' => $jornada->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-07 09:00:00',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-10-07 18:35:00', 'Europe/Madrid'));
        $this->actingAs($ana)->post(route('jornada.seguir'))->assertSessionHas('ok');
        $this->actingAs($ana)
            ->post(route('jornada.salida'))
            ->assertSessionHas('ok', 'Guardado. Salida a las 18:35.');

        $this->assertSame('18:35', Tramo::query()->first()->ended_at->timezone('Europe/Madrid')->format('H:i'));
    }

    public function test_el_festivo_del_centro_pregunta_si_hay_trabajo(): void
    {
        $this->festivosPublicos($this->muestraNager());
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        Carbon::setTestNow(Carbon::parse('2026-04-02 10:30:00', 'Europe/Madrid'));

        $this->actingAs($ana)
            ->get(route('jornada'))
            ->assertSee('¿Hay trabajo real?', false)
            ->assertDontSee('Empezar jornada', false);

        $this->actingAs($ana)->post(route('jornada.festivo'), ['respuesta' => 'no'])->assertSessionHas('ok');
        $this->actingAs($ana)->get(route('jornada'))->assertDontSee('Empezar jornada', false);
        $this->assertDatabaseCount('jornadas', 0);

        $this->actingAs($ana)->post(route('jornada.festivo'), ['respuesta' => 'si'])->assertSessionHas('ok');
        $this->actingAs($ana)->get(route('jornada'))->assertSee('Empezar jornada', false);
    }

    public function test_un_solo_cierre_sin_completar_ya_se_avisa_y_no_cambia_el_registro(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        $jornada = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-06']);
        $tramo = Tramo::create([
            'jornada_id' => $jornada->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-06 09:00:00',
            'ended_at' => '2026-10-06 18:00:00',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-07 08:00:00', 'Europe/Madrid'));

        $this->actingAs($ana)->get(route('jornada'))->assertDontSee('cierres sin completar', false);

        Aviso::create(['user_id' => $ana->id, 'tipo' => 'cierre', 'work_date' => '2026-10-06', 'cuenta' => true]);
        $this->actingAs($ana)->get(route('jornada'))
            ->assertDontSee('El martes 6 de octubre quedó el cierre sin completar', false);
        $this->assertNotNull(Aviso::query()->where('user_id', $ana->id)->where('tipo', 'cierre')->first()->responded_at);

        Aviso::create(['user_id' => $ana->id, 'tipo' => 'olvido', 'work_date' => '2026-10-05', 'cuenta' => true]);
        $this->actingAs($ana)->get(route('jornada'))
            ->assertSee('El lunes 5 de octubre falta la hora de salida', false)
            ->assertDontSee('Tienes 2 días sin explicar', false);

        $this->assertSame('18:00', $tramo->fresh()->ended_at->timezone('Europe/Madrid')->format('H:i'));
    }

    public function test_un_aviso_sin_explicar_no_caduca_con_el_tiempo(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        Carbon::setTestNow(Carbon::parse('2026-10-07 08:00:00', 'Europe/Madrid'));

        Aviso::create(['user_id' => $ana->id, 'tipo' => 'cierre', 'work_date' => '2026-07-01', 'cuenta' => true]);

        $this->actingAs($ana)->get(route('jornada'))->assertSee('El miércoles 1 de julio quedó el cierre sin completar', false);
    }

    public function test_un_aviso_del_anio_anterior_no_sale_en_pendientes(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        Carbon::setTestNow(Carbon::parse('2026-10-07 08:00:00', 'Europe/Madrid'));

        Aviso::create(['user_id' => $ana->id, 'tipo' => 'cierre', 'work_date' => '2025-12-15', 'cuenta' => true]);
        Aviso::create(['user_id' => $ana->id, 'tipo' => 'cierre', 'work_date' => '2026-07-01', 'cuenta' => true]);

        $this->actingAs($ana)
            ->get(route('jornada'))
            ->assertOk()
            ->assertSee('El miércoles 1 de julio quedó el cierre sin completar', false)
            ->assertDontSee('15 de diciembre', false);

        $this->actingAs($ana)
            ->get(route('registro'))
            ->assertOk()
            ->assertDontSee('Pendiente de explicar', false)
            ->assertDontSee('15 de diciembre', false);

        $this->assertDatabaseCount('avisos', 2);
    }

    public function test_explicar_un_aviso_deja_de_contarlo(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        Carbon::setTestNow(Carbon::parse('2026-10-07 08:00:00', 'Europe/Madrid'));

        Aviso::create([
            'user_id' => $ana->id,
            'tipo' => 'cierre',
            'work_date' => '2026-10-06',
            'cuenta' => true,
            'responded_at' => '2026-10-06 19:00:00',
        ]);

        $this->actingAs($ana)->get(route('jornada'))->assertDontSee('cierres sin completar', false);
        $this->assertDatabaseCount('avisos', 1);
    }

    public function test_mi_registro_abre_esta_semana_por_defecto(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        Carbon::setTestNow(Carbon::parse('2026-10-20 13:00:00', 'Europe/Madrid'));

        $this->actingAs($ana)
            ->get(route('registro'))
            ->assertOk()
            ->assertSee('aria-current="page" >Esta semana', false)
            ->assertSee('19–25 de octubre', false);
    }

    public function test_el_registro_muestra_el_dia_sin_fichaje_para_explicarlo(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        Carbon::setTestNow(Carbon::parse('2026-10-20 13:00:00', 'Europe/Madrid'));
        Aviso::create(['user_id' => $ana->id, 'tipo' => 'inicio', 'work_date' => '2026-10-19', 'cuenta' => true]);
        Aviso::create(['user_id' => $ana->id, 'tipo' => 'inicio', 'work_date' => '2026-10-20', 'cuenta' => true]);
        Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-20']);

        $this->actingAs($ana)
            ->get(route('jornada'))
            ->assertSee('El lunes 19 de octubre no quedó registrada la entrada', false)
            ->assertSee(route('registro.dia', '2026-10-19').'?abrir=registrar-horas', false);

        $this->actingAs($ana)
            ->get(route('registro.dia', '2026-10-19').'?abrir=registrar-horas')
            ->assertOk()
            ->assertSee('Mi registro', false)
            ->assertSee('Lunes, 19 de octubre', false)
            ->assertSee('Este día no tiene fichaje.', false)
            ->assertSee('Registrar las horas', false)
            ->assertSee('data-abierto', false)
            ->assertSee(route('registro.dia.horas', '2026-10-19'), false);

        $this->actingAs($ana)
            ->get(route('registro'))
            ->assertDontSee('Pendiente de explicar', false)
            ->assertDontSee('Qué pasó', false)
            ->assertDontSee('martes 20 de octubre', false);

        $this->actingAs($ana)
            ->post(route('registro.explicar'), [
                'work_date' => '2026-10-19',
                'body' => 'Ese lunes no fui a la oficina.',
            ])
            ->assertSessionHas('ok');

        $this->actingAs($ana)
            ->get(route('registro'))
            ->assertDontSee('Pendiente de explicar', false)
            ->assertSee('Ese lunes no fui a la oficina.', false);

        $this->actingAs($ana)->get(route('jornada'))->assertDontSee('lunes 19 de octubre', false);
    }

    public function test_revisar_un_dia_antiguo_sin_fichaje_abre_la_explicacion(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        Carbon::setTestNow(Carbon::parse('2026-10-26 13:00:00', 'Europe/Madrid'));
        Aviso::create(['user_id' => $ana->id, 'tipo' => 'inicio', 'work_date' => '2026-10-08', 'cuenta' => true]);

        $this->actingAs($ana)
            ->get(route('jornada'))
            ->assertDontSee(route('registro.dia', '2026-10-08').'?abrir=registrar-horas', false);

        $this->actingAs($ana)
            ->get(route('registro.dia', ['fecha' => '2026-10-08', 'abrir' => 'registrar-horas']))
            ->assertOk()
            ->assertSee('Explica este día', false)
            ->assertSee('data-abierto', false)
            ->assertSee(route('registro.explicar'), false)
            ->assertDontSee('Registrar las horas', false);

        $this->actingAs($ana)
            ->post(route('registro.explicar'), [
                'work_date' => '2026-10-08',
                'body' => 'Ese jueves no fui a la oficina.',
            ])
            ->assertSessionHas('ok');

        $this->actingAs($ana)->get(route('jornada'))->assertDontSee('8 de octubre', false);
    }

    public function test_la_jefa_revisa_un_dia_antiguo_sin_fichaje_y_registra_las_horas(): void
    {
        $marta = $this->persona('Marta Ruiz', 'marta.ruiz@abaco.test', 'Madrid', 'jefa');
        $this->horario($marta);
        Carbon::setTestNow(Carbon::parse('2026-10-26 13:00:00', 'Europe/Madrid'));
        Aviso::create(['user_id' => $marta->id, 'tipo' => 'inicio', 'work_date' => '2026-10-08', 'cuenta' => true]);

        $this->actingAs($marta)
            ->get(route('registro.dia', ['fecha' => '2026-10-08', 'abrir' => 'registrar-horas']))
            ->assertOk()
            ->assertSee('Registrar las horas', false)
            ->assertSee('data-abierto', false)
            ->assertDontSee('Explica este día', false);
    }

    public function test_revisar_abre_ese_dia_en_mi_registro_y_la_nota_va_en_corregir(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        Carbon::setTestNow(Carbon::parse('2026-10-20 13:00:00', 'Europe/Madrid'));
        $jornada = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-19']);
        $tramo = Tramo::create([
            'jornada_id' => $jornada->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-19 09:00:00',
            'ended_at' => '2026-10-19 18:00:00',
        ]);
        Aviso::create(['user_id' => $ana->id, 'tipo' => 'inicio', 'work_date' => '2026-10-19', 'cuenta' => true]);

        $this->actingAs($ana)
            ->get(route('jornada'))
            ->assertSee(route('registro.show', $jornada).'?abrir=corregir', false)
            ->assertDontSee('Qué pasó', false);

        $this->actingAs($ana)
            ->get(route('registro.show', ['jornada' => $jornada, 'abrir' => 'corregir']))
            ->assertOk()
            ->assertSee('Mi registro', false)
            ->assertSee('Lunes, 19 de octubre', false)
            ->assertSee('Corregir horas', false)
            ->assertSee('data-abierto', false)
            ->assertDontSee('Qué pasó', false)
            ->assertSee(route('registro', ['periodo' => 'semana']).'#dia-2026-10-19', false);

        $this->actingAs($ana)->post(route('tramos.corregir', $tramo), [
            'inicio' => '09:00',
            'fin' => '18:00',
            'motivo' => 'olvido',
            'nota' => 'Llegué a la hora y se me olvidó anotarlo.',
        ])->assertSessionHas('ok');

        $this->actingAs($ana)->get(route('jornada'))->assertDontSee('lunes 19 de octubre', false);
        $this->actingAs($ana)
            ->get(route('registro.show', $jornada))
            ->assertSee('Llegué a la hora y se me olvidó anotarlo.', false);
    }

    public function test_registrar_las_horas_de_un_dia_sin_fichaje(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        Carbon::setTestNow(Carbon::parse('2026-10-20 13:00:00', 'Europe/Madrid'));
        Aviso::create(['user_id' => $ana->id, 'tipo' => 'inicio', 'work_date' => '2026-10-19', 'cuenta' => true]);

        $this->actingAs($ana)
            ->post(route('registro.dia.horas', '2026-10-19'), ['inicio' => '09:00', 'fin' => '14:00'])
            ->assertRedirect();

        $creada = Jornada::query()->where('user_id', $ana->id)->whereDate('work_date', '2026-10-19')->first();
        $this->assertNotNull($creada);
        $this->assertSame('09:00', $creada->tramos->first()->started_at->timezone('Europe/Madrid')->format('H:i'));
        $this->assertSame('14:00', $creada->tramos->first()->ended_at->timezone('Europe/Madrid')->format('H:i'));

        $this->actingAs($ana)
            ->get(route('registro.show', $creada))
            ->assertSee('09:00–14:00', false)
            ->assertSee('Fichado fuera de plazo', false)
            ->assertSee('Registrar las horas', false);

        $this->actingAs($ana)
            ->get(route('registro'))
            ->assertSee('Fichado fuera de plazo', false);

        $this->actingAs($ana)
            ->get(route('registro', ['estado' => 'fuera_plazo']))
            ->assertSee('Fichado fuera de plazo', false);

        $this->actingAs($ana)->get(route('jornada'))->assertDontSee('lunes 19 de octubre', false);
    }

    public function test_el_fallo_comun_y_el_horario_no_cuentan(): void
    {
        $marta = $this->persona('Marta Ruiz', 'marta.ruiz@abaco.test', 'Madrid', 'jefa');
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        $anterior = Aviso::create([
            'user_id' => $ana->id,
            'tipo' => 'inicio',
            'work_date' => '2026-10-06',
            'cuenta' => true,
        ]);
        $afectado = Aviso::create([
            'user_id' => $ana->id,
            'tipo' => 'cierre',
            'work_date' => '2026-10-07',
            'cuenta' => true,
        ]);

        $this->actingAs($marta)->post(route('equipo.horario', $ana), [
            'morning_start' => '08:30',
            'morning_end' => '14:00',
            'afternoon_start' => '15:00',
            'afternoon_end' => '18:00',
            'effective_from' => '2026-10-07',
        ])->assertSessionHas('ok');

        $this->assertTrue($anterior->fresh()->cuenta);
        $this->assertNull($anterior->fresh()->exclusion);
        $this->assertFalse($afectado->fresh()->cuenta);
        $this->assertSame('horario', $afectado->fresh()->exclusion);

        $this->actingAs($marta)->post(route('calendario.fallo'), [
            'falla_date' => '2026-10-07',
            'note' => 'Caída del calendario',
        ])->assertSessionHas('ok');

        $this->actingAs($ana)->get(route('jornada'));
        $deHoy = Aviso::query()->where('user_id', $ana->id)->whereDate('work_date', '2026-10-07')->first();
        $this->assertNotNull($deHoy);
        $this->assertFalse($deHoy->cuenta);
        $this->assertSame('comun', $deHoy->exclusion);
    }

    public function test_la_consulta_pide_motivo_y_queda_escrita(): void
    {
        $marta = $this->persona('Marta Ruiz', 'marta.ruiz@abaco.test', 'Madrid', 'jefa');
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-06']);

        $this->actingAs($marta)
            ->get(route('equipo.registro', $ana))
            ->assertSee('hace falta un motivo', false)
            ->assertDontSee('Descargar CSV', false);

        $this->actingAs($marta)
            ->post(route('equipo.motivo', $ana), ['reason' => 'Revisión del artículo 34.9'])
            ->assertRedirect();

        $this->actingAs($marta)
            ->get(route('equipo.registro', $ana))
            ->assertSee('Descargar CSV', false);

        $this->assertDatabaseHas('consultas', [
            'viewer_id' => $marta->id,
            'subject_id' => $ana->id,
            'reason' => 'Revisión del artículo 34.9',
        ]);
    }

    public function test_cambiar_una_hora_conserva_la_anterior(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $jornada = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-07']);
        $tramo = Tramo::create([
            'jornada_id' => $jornada->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-07 09:30:00',
            'ended_at' => '2026-10-07 18:00:00',
        ]);

        $this->actingAs($ana)->post(route('tramos.corregir', $tramo), [
            'campo' => 'started_at',
            'hora' => '09:00',
            'motivo' => 'hora',
        ])->assertSessionHas('ok');

        $this->assertSame('09:00', $tramo->fresh()->started_at->timezone('Europe/Madrid')->format('H:i'));
        $this->assertDatabaseHas('correcciones', [
            'tramo_id' => $tramo->id,
            'user_id' => $ana->id,
        ]);
        $this->actingAs($ana)
            ->get(route('registro.show', $jornada))
            ->assertSee('Antes 09:30', false)
            ->assertSee('ahora 09:00', false);
    }

    public function test_en_el_dia_se_guarda_el_inicio_el_fin_o_los_dos(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');

        $soloInicio = Tramo::create([
            'jornada_id' => Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-07'])->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-07 09:00:00',
        ]);
        $this->actingAs($ana)->post(route('tramos.corregir', $soloInicio), [
            'inicio' => '08:30',
            'motivo' => 'hora',
        ])->assertSessionHas('ok');
        $this->assertSame('08:30', $soloInicio->fresh()->started_at->timezone('Europe/Madrid')->format('H:i'));
        $this->assertNull($soloInicio->fresh()->ended_at);

        $soloFin = Tramo::create([
            'jornada_id' => Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-06'])->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-06 09:00:00',
        ]);
        $this->actingAs($ana)->post(route('tramos.corregir', $soloFin), [
            'fin' => '10:00',
            'motivo' => 'hora',
        ])->assertSessionHas('ok');
        $this->assertSame('09:00', $soloFin->fresh()->started_at->timezone('Europe/Madrid')->format('H:i'));
        $this->assertSame('10:00', $soloFin->fresh()->ended_at->timezone('Europe/Madrid')->format('H:i'));
        $this->assertSame($ana->id, $soloFin->fresh()->cerrado_por);

        $losDos = Tramo::create([
            'jornada_id' => Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-05'])->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-05 09:00:00',
        ]);
        $this->actingAs($ana)
            ->get(route('registro.show', $losDos->jornada_id))
            ->assertSee('Hora de inicio', false)
            ->assertSee('Hora de fin', false);
        $this->actingAs($ana)->post(route('tramos.corregir', $losDos), [
            'inicio' => '08:00',
            'fin' => '10:15',
            'motivo' => 'hora',
        ])->assertSessionHas('ok');
        $this->assertSame('08:00', $losDos->fresh()->started_at->timezone('Europe/Madrid')->format('H:i'));
        $this->assertSame('10:15', $losDos->fresh()->ended_at->timezone('Europe/Madrid')->format('H:i'));
    }

    public function test_una_correccion_puede_pisar_otro_rato(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $jornada = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-07']);
        Tramo::create([
            'jornada_id' => $jornada->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-07 09:00:00',
            'ended_at' => '2026-10-07 12:00:00',
        ]);
        $tarde = Tramo::create([
            'jornada_id' => $jornada->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-07 15:00:00',
            'ended_at' => '2026-10-07 18:00:00',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-07 19:00:00', 'Europe/Madrid'));

        $this->actingAs($ana)->post(route('tramos.corregir', $tarde), [
            'campo' => 'started_at',
            'hora' => '11:00',
            'motivo' => 'hora',
        ])->assertSessionHas('ok');

        $this->assertSame('11:00', $tarde->fresh()->started_at->timezone('Europe/Madrid')->format('H:i'));
        $this->assertSame(540, app(JornadaService::class)->minutosDeJornada($jornada->fresh('tramos')));
    }

    public function test_el_formulario_completo_tambien_permite_pisar_otro_rato(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $jornada = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-07']);
        $tramo = Tramo::create([
            'jornada_id' => $jornada->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-07 09:00:00',
            'ended_at' => '2026-10-07 12:00:00',
        ]);
        Tramo::create([
            'jornada_id' => $jornada->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-07 13:00:00',
            'ended_at' => '2026-10-07 18:00:00',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-07 19:00:00', 'Europe/Madrid'));

        $this->actingAs($ana)
            ->post(route('tramos.corregir', $tramo), [
                'inicio' => '09:00',
                'fin' => '14:00',
                'motivo' => 'hora',
            ])
            ->assertSessionHas('ok');

        $corregido = $tramo->fresh();
        $this->assertSame('09:00', $corregido->started_at->timezone('Europe/Madrid')->format('H:i'));
        $this->assertSame('14:00', $corregido->ended_at->timezone('Europe/Madrid')->format('H:i'));
        $this->assertSame(540, app(JornadaService::class)->minutosDeJornada($jornada->fresh('tramos')));
    }

    public function test_corregir_una_pausa_existente_la_actualiza_y_la_descuenta(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $jornada = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-07']);
        Tramo::create([
            'jornada_id' => $jornada->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-07 09:00:00',
            'ended_at' => '2026-10-07 18:00:00',
        ]);
        $pausa = Tramo::create([
            'jornada_id' => $jornada->id,
            'tipo' => Tramo::PAUSA,
            'started_at' => '2026-10-07 14:00:00',
            'ended_at' => '2026-10-07 15:00:00',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-07 19:00:00', 'Europe/Madrid'));

        $this->actingAs($ana)
            ->post(route('tramos.corregir', $pausa), [
                'motivo' => 'fichar_pausa',
                'inicio' => '13:30',
                'fin' => '14:30',
            ])
            ->assertSessionHas('ok');

        $this->assertSame('13:30', $pausa->fresh()->started_at->timezone('Europe/Madrid')->format('H:i'));
        $this->assertSame('14:30', $pausa->fresh()->ended_at->timezone('Europe/Madrid')->format('H:i'));
        $this->assertSame(480, app(JornadaService::class)->minutosDeJornada($jornada->fresh('tramos'), now()));

        $this->actingAs($ana)
            ->post(route('tramos.corregir', $pausa), [
                'motivo' => 'fichar_pausa',
                'inicio' => '13:30',
                'fin' => '14:30',
            ])
            ->assertSessionHas('ok');
    }

    public function test_dos_correcciones_seguidas_no_dejan_una_hora_imposible(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $jornada = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-07']);
        $tramo = Tramo::create([
            'jornada_id' => $jornada->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-07 09:00:00',
            'ended_at' => '2026-10-07 18:00:00',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-10-07 18:30:00', 'Europe/Madrid'));

        $this->actingAs($ana)->post(route('tramos.corregir', $tramo), [
            'campo' => 'started_at',
            'hora' => '17:00',
            'motivo' => 'hora',
        ])->assertSessionHas('ok');

        $this->actingAs($ana)->post(route('tramos.corregir', $tramo), [
            'campo' => 'ended_at',
            'hora' => '10:00',
            'motivo' => 'hora',
        ])->assertSessionHas('aviso');

        $fresco = $tramo->fresh();
        $this->assertSame('17:00', $fresco->started_at->timezone('Europe/Madrid')->format('H:i'));
        $this->assertSame('18:00', $fresco->ended_at->timezone('Europe/Madrid')->format('H:i'));
    }

    public function test_mover_una_pausa_fichada_recompone_el_trabajo_sin_perder_minutos(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $jornada = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-07']);
        $trabajo = Tramo::create([
            'jornada_id' => $jornada->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-07 09:00:00',
            'ended_at' => '2026-10-07 18:00:00',
            'cerrado_por' => $ana->id,
            'cerrado_at' => '2026-10-07 18:00:00',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-07 19:00:00', 'Europe/Madrid'));
        $servicio = app(JornadaService::class);
        $servicio->ficharPausa($ana, $trabajo, '14:00', '15:00', null);
        $pausa = $jornada->tramos()->where('tipo', Tramo::PAUSA)->firstOrFail();
        $trabajos = $jornada->tramos()->where('tipo', Tramo::TRABAJO)->get()->sortBy('started_at');
        $antes = $trabajos->first();
        $despues = $trabajos->last();
        $this->assertSame(480, $servicio->minutosDeJornada($jornada->fresh('tramos')));

        $this->actingAs($ana)->post(route('tramos.corregir', $pausa), [
            'motivo' => 'fichar_pausa',
            'inicio' => '13:30',
            'fin' => '14:30',
        ])->assertSessionHas('ok');

        $this->assertSame(480, $servicio->minutosDeJornada($jornada->fresh('tramos')));
        $this->assertSame('13:30', $antes->fresh()->ended_at->format('H:i'));
        $this->assertSame('14:30', $despues->fresh()->started_at->format('H:i'));
        $this->assertSame($ana->id, $despues->fresh()->cerrado_por);
        $this->assertSame('18:00', $despues->fresh()->cerrado_at->format('H:i'));
        $this->assertDatabaseHas('correcciones', ['tramo_id' => $antes->id, 'field' => 'ended_at', 'reason' => 'fichar_pausa']);
        $this->assertDatabaseHas('correcciones', ['tramo_id' => $despues->id, 'field' => 'started_at', 'reason' => 'fichar_pausa']);
    }

    public function test_la_reunion_no_repite_un_rato_ya_anotado(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        $jornada = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-07']);
        Tramo::create([
            'jornada_id' => $jornada->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-07 09:00:00',
            'ended_at' => '2026-10-07 10:00:00',
            'fuera_del_equipo' => true,
            'situacion' => 'fuera',
        ]);

        $this->actingAs($ana)
            ->post(route('jornada.reunion'))
            ->assertSessionHas('aviso');

        $this->assertSame(1, Tramo::query()->count());
    }

    public function test_el_registro_cuenta_igual_que_hoy_cuando_la_jornada_sigue_abierta(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        $jornada = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-07']);
        Tramo::create([
            'jornada_id' => $jornada->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-07 09:00:00',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-10-07 21:00:00', 'Europe/Madrid'));

        $this->actingAs($ana)
            ->get(route('registro'))
            ->assertSee('9 h 00', false)
            ->assertSee('5–11 de octubre', false)
            ->assertDontSee('1–7 de octubre', false)
            ->assertDontSee('12 h', false);

        $this->actingAs($ana)
            ->get(route('registro', ['desde' => '2026-10-07', 'hasta' => '2026-10-07']))
            ->assertSee('9 h 00', false)
            ->assertDontSee('12 h', false);

        $this->actingAs($ana)
            ->get(route('registro.show', $jornada))
            ->assertSee('9 h 00', false)
            ->assertDontSee('12 h', false);

        $copia = $this->actingAs($ana)->get(route('registro.csv', ['desde' => '2026-10-07', 'hasta' => '2026-10-07']))->assertOk()->streamedContent();
        $this->assertStringContainsString('9 h 0 min', $copia);
        $this->assertStringNotContainsString('12 h', $copia);
    }

    public function test_una_entrada_sin_salida_de_ayer_no_inventa_la_hora(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        $jornada = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-06']);
        Tramo::create([
            'jornada_id' => $jornada->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-06 09:00:00',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-10-07 10:45:00', 'Europe/Madrid'));

        $this->actingAs($ana)
            ->post(route('jornada.entrada'))
            ->assertSessionHas('aviso');

        $this->actingAs($ana)
            ->get(route('jornada'))
            ->assertOk()
            ->assertSee('No cerraste el martes', false)
            ->assertDontSee('Empezar jornada', false);

        $this->actingAs($ana)
            ->get(route('registro.show', $jornada))
            ->assertOk()
            ->assertSee('sin salida', false)
            ->assertSee('0 h 00', false)
            ->assertDontSee('09:00–ahora', false);

        $this->actingAs($ana)
            ->get(route('registro'))
            ->assertOk()
            ->assertSee('sin salida', false)
            ->assertDontSee('09:00 – 10:45', false);

        $this->assertSame(0, app(JornadaService::class)->minutosRegistrados($ana, $jornada->fresh(['tramos'])));
        $this->assertDatabaseCount('jornadas', 1);
    }

    public function test_el_acceso_no_se_queda_congelado_si_la_sesion_sigue_abierta(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');

        $acceso = $this->get(route('login'))->assertOk();
        $this->assertStringContainsString('no-store', (string) $acceso->headers->get('Cache-Control'));
        $acceso->assertSee('evento.persisted', false);

        $this->actingAs($ana)
            ->get(route('login'))
            ->assertRedirect(route('jornada'));
    }

    public function test_fuera_de_la_demo_no_se_borran_fichajes_ni_se_ven_las_cuentas(): void
    {
        config(['app.demo' => true]);
        $this->app['env'] = 'production';
        $this->withoutMiddleware(ProtectFormRequests::class);
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-07']);

        $this->get(route('login'))
            ->assertDontSee('Cuentas de prueba', false);

        $this->actingAs($ana)
            ->get(route('jornada'))
            ->assertDontSee('Borrar fichaje de hoy', false)
            ->assertDontSee('Borrar fichaje de ayer', false)
            ->assertDontSee('Borrar fichaje de hace 3 días', false);

        $this->actingAs($ana)
            ->post(route('jornada.borrar-prueba'))
            ->assertForbidden();

        $this->actingAs($ana)
            ->post(route('jornada.borrar-ayer'))
            ->assertForbidden();

        $this->actingAs($ana)
            ->post(route('jornada.borrar-tres-dias'))
            ->assertForbidden();

        $this->assertSame(1, Jornada::query()->count());
    }

    public function test_borrar_el_fichaje_de_ayer_deja_el_de_hoy(): void
    {
        config(['app.demo' => true]);
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        $ayer = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-06']);
        Tramo::create([
            'jornada_id' => $ayer->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-06 09:00:00',
            'ended_at' => '2026-10-06 18:00:00',
        ]);
        Aviso::create(['user_id' => $ana->id, 'tipo' => 'cierre', 'work_date' => '2026-10-06', 'cuenta' => true]);
        $hoy = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-07']);

        $this->actingAs($ana)
            ->post(route('jornada.borrar-ayer'))
            ->assertRedirect();

        $this->assertDatabaseMissing('jornadas', ['id' => $ayer->id]);
        $this->assertDatabaseMissing('tramos', ['jornada_id' => $ayer->id]);
        $this->assertTrue(
            Aviso::query()
                ->where('user_id', $ana->id)
                ->where('tipo', 'inicio')
                ->whereDate('work_date', '2026-10-06')
                ->whereNull('responded_at')
                ->exists()
        );
        $this->assertDatabaseHas('jornadas', ['id' => $hoy->id]);
    }

    public function test_borrar_el_fichaje_de_hace_tres_dias_deja_los_dias_posteriores(): void
    {
        config(['app.demo' => true]);
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        $haceTresDias = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-05']);
        Tramo::create([
            'jornada_id' => $haceTresDias->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-05 09:00:00',
            'ended_at' => '2026-10-05 18:00:00',
        ]);
        $ayer = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-06']);
        $hoy = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-07']);
        Carbon::setTestNow(Carbon::parse('2026-10-08 10:45:00', 'Europe/Madrid'));

        $this->actingAs($ana)
            ->post(route('jornada.borrar-tres-dias'))
            ->assertRedirect();

        $this->assertDatabaseMissing('jornadas', ['id' => $haceTresDias->id]);
        $this->assertDatabaseHas('jornadas', ['id' => $ayer->id]);
        $this->assertDatabaseHas('jornadas', ['id' => $hoy->id]);
        $this->assertTrue(
            Aviso::query()
                ->where('user_id', $ana->id)
                ->where('tipo', 'inicio')
                ->whereDate('work_date', '2026-10-05')
                ->whereNull('responded_at')
                ->exists()
        );
    }

    public function test_sin_el_fichaje_de_ayer_hay_que_explicarlo_antes_de_empezar_hoy(): void
    {
        config(['app.demo' => true]);
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        $ayer = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-06']);
        Tramo::create([
            'jornada_id' => $ayer->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-06 09:00:00',
            'ended_at' => '2026-10-06 18:00:00',
        ]);

        $this->actingAs($ana)->post(route('jornada.borrar-ayer'))->assertRedirect();

        $this->actingAs($ana)
            ->get(route('jornada'))
            ->assertSee('El martes 6 de octubre no quedó registrada la entrada', false)
            ->assertSee('Revisar', false)
            ->assertDontSee('Empezar jornada', false)
            ->assertDontSee('Ya estaba trabajando', false);

        $this->actingAs($ana)
            ->post(route('jornada.entrada'))
            ->assertSessionHas('aviso', 'Ayer no quedó registrada la entrada. Explícalo antes de empezar hoy.');
        $this->assertDatabaseCount('jornadas', 0);
    }

    public function test_tras_cerrar_el_lunes_el_martes_sin_fichar_bloquea_el_miercoles(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        Carbon::setTestNow(Carbon::parse('2026-10-28 10:45:00', 'Europe/Madrid'));
        $lunes = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-26']);
        Tramo::create([
            'jornada_id' => $lunes->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-26 09:00:00',
            'ended_at' => '2026-10-26 18:00:00',
        ]);

        $this->actingAs($ana)
            ->get(route('jornada'))
            ->assertSee('El martes 27 de octubre no quedó registrada la entrada', false)
            ->assertSee('Revisar', false)
            ->assertDontSee('Empezar jornada', false);

        $this->actingAs($ana)
            ->post(route('jornada.entrada'))
            ->assertSessionHas('aviso', 'Ayer no quedó registrada la entrada. Explícalo antes de empezar hoy.');
        $this->assertDatabaseMissing('jornadas', [
            'user_id' => $ana->id,
            'work_date' => '2026-10-28',
        ]);
    }

    public function test_sin_el_fichaje_del_viernes_no_puede_empezar_el_lunes(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        Carbon::setTestNow(Carbon::parse('2026-10-26 10:45:00', 'Europe/Madrid'));
        Aviso::create([
            'user_id' => $ana->id,
            'tipo' => 'inicio',
            'work_date' => '2026-10-23',
            'cuenta' => true,
        ]);

        $this->actingAs($ana)
            ->get(route('jornada'))
            ->assertSee('El viernes 23 de octubre no quedó registrada la entrada', false)
            ->assertDontSee('Empezar jornada', false);

        $this->actingAs($ana)
            ->post(route('jornada.entrada'))
            ->assertSessionHas('aviso', 'El viernes 23 de octubre no quedó registrada la entrada. Explícalo antes de empezar hoy.');
        $this->assertDatabaseCount('jornadas', 0);
    }

    public function test_un_dia_sin_fichar_se_avisa_durante_siete_dias(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        Carbon::setTestNow(Carbon::parse('2026-10-30 10:45:00', 'Europe/Madrid'));
        Aviso::create(['user_id' => $ana->id, 'tipo' => 'inicio', 'work_date' => '2026-10-23', 'cuenta' => true]);

        $this->actingAs($ana)
            ->get(route('jornada'))
            ->assertOk()
            ->assertSee('El viernes 23 de octubre no quedó registrada la entrada', false)
            ->assertDontSee('Empezar jornada', false);
    }

    public function test_un_dia_sin_fichar_deja_de_avisar_pasados_siete_dias(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        Carbon::setTestNow(Carbon::parse('2026-10-30 10:45:00', 'Europe/Madrid'));
        Aviso::create(['user_id' => $ana->id, 'tipo' => 'inicio', 'work_date' => '2026-10-22', 'cuenta' => true]);

        $this->actingAs($ana)
            ->get(route('jornada'))
            ->assertOk()
            ->assertDontSee('El jueves 22 de octubre no quedó registrada la entrada', false)
            ->assertSee('Empezar jornada', false);

        $this->actingAs($ana)
            ->post(route('jornada.entrada'))
            ->assertRedirect()
            ->assertSessionHas('ok');
    }

    public function test_la_incidencia_de_conexion_declara_horas_sin_fichar_en_directo(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);

        $this->actingAs($ana)->get(route('jornada.incidencia.crear'))
            ->assertOk()
            ->assertSee('10 a 500 caracteres', false)
            ->assertDontSee('0/500 caracteres', false);

        $this->actingAs($ana)
            ->post(route('jornada.incidencia'), [
                'motivo' => str_repeat('a', 501),
            ])
            ->assertSessionHasErrors('motivo');
        $this->assertDatabaseCount('incidencias', 0);

        $this->actingAs($ana)
            ->post(route('jornada.incidencia'), [
                'motivo' => 'Se cayó la red',
            ])
            ->assertSessionHas('ok');

        $this->assertDatabaseCount('tramos', 0);
        $this->assertDatabaseHas('incidencias', [
            'user_id' => $ana->id,
            'cuerpo' => 'Se cayó la red',
        ]);

        $this->actingAs($ana)
            ->get(route('jornada'))
            ->assertSee('Empezar jornada', false)
            ->assertSee('Incidencia', false);
    }

    public function test_una_incidencia_enviada_aparece_en_el_estado_del_dia(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        $jornada = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-07']);
        Tramo::create([
            'jornada_id' => $jornada->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-07 09:00:00',
            'ended_at' => '2026-10-07 18:00:00',
        ]);

        $this->actingAs($ana)
            ->post(route('jornada.incidencia'), ['motivo' => 'Se cayó la conexión'])
            ->assertSessionHas('ok');

        $this->actingAs($ana)
            ->get(route('registro', ['desde' => '2026-10-07', 'hasta' => '2026-10-07']))
            ->assertSee('Incidencia', false);

        $this->actingAs($ana)
            ->get(route('registro', [
                'desde' => '2026-10-07',
                'hasta' => '2026-10-07',
                'incidencia' => 'si',
            ]))
            ->assertSee('Miércoles, 7 oct', false);
    }

    public function test_el_chat_de_una_incidencia_se_cierra_al_resolverla(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $marta = $this->persona('Marta Ruiz', 'marta.ruiz@abaco.test', 'Madrid', 'jefa');
        $lucia = $this->persona('Lucía Vega', 'lucia.vega@abaco.test', 'Madrid');

        $this->actingAs($ana)->get(route('jornada.incidencia.crear'))
            ->assertOk()
            ->assertSee('Historial de incidencias', false)
            ->assertSee('Abrir incidencia', false)
            ->assertSee('Qué pasó', false);

        $this->actingAs($ana)->post(route('jornada.incidencia'), [
            'motivo' => 'Se cayó la red',
        ])->assertRedirect(route('jornada.incidencia.crear'));

        $incidencia = Incidencia::query()->where('user_id', $ana->id)->firstOrFail();
        $this->assertSame(Incidencia::PENDIENTE, $incidencia->estado);

        $this->actingAs($ana)
            ->get(route('jornada.incidencia.crear', ['incidencia' => $incidencia->id]))
            ->assertSee('Chat', false)
            ->assertSee('Escribe un mensaje', false)
            ->assertSee('Se cayó la red', false);

        $this->actingAs($lucia)->post(route('incidencias.mensaje', $incidencia), [
            'cuerpo' => 'No es mía',
        ])->assertForbidden();

        $this->actingAs($ana)->post(route('incidencias.mensaje', $incidencia), [
            'cuerpo' => 'Sigue sin red',
        ])->assertSessionHas('ok');

        $this->actingAs($marta)->get(route('incidencias.show', $incidencia))
            ->assertSee('Sigue sin red', false)
            ->assertSee('Resolver', false);

        $this->actingAs($marta)->post(route('incidencias.mensaje', $incidencia), [
            'cuerpo' => 'Ya lo miro',
        ])->assertSessionHas('ok');

        $this->actingAs($ana)
            ->get(route('jornada.incidencia.crear', ['incidencia' => $incidencia->id]))
            ->assertSee('Ya lo miro', false);

        $this->actingAs($ana)->post(route('incidencias.resolver', $incidencia))
            ->assertRedirect(route('jornada'));

        $this->actingAs($marta)->post(route('incidencias.resolver', $incidencia))
            ->assertSessionHas('ok', 'Resuelta. Queda en el archivo.');

        $this->assertSame(Incidencia::RESUELTA, $incidencia->fresh()->estado);

        $this->actingAs($ana)->post(route('incidencias.mensaje', $incidencia), [
            'cuerpo' => 'Otra vez',
        ])->assertSessionHas('aviso', 'Esta incidencia está resuelta. El chat está cerrado.');

        $this->actingAs($marta)->get(route('incidencias.show', $incidencia))
            ->assertSee('El chat está cerrado.', false)
            ->assertDontSee('>Resolver<', false);

        $this->actingAs($ana)->get(route('jornada.incidencia.crear'))
            ->assertSee('estado-resuelta', false)
            ->assertDontSee('estado-abierta', false);
    }

    public function test_la_jefa_envia_una_incidencia_a_una_persona_y_ella_puede_contestar(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $marta = $this->persona('Marta Ruiz', 'marta.ruiz@abaco.test', 'Madrid', 'jefa');
        $lucia = $this->persona('Lucía Vega', 'lucia.vega@abaco.test', 'Madrid');
        $otraJefa = $this->persona('Elena Soto', 'elena.soto@abaco.test', 'Madrid', 'jefa');

        $this->actingAs($marta)->get(route('jornada'))
            ->assertDontSee(route('jornada.incidencia.crear'), false)
            ->assertSee(route('incidencias.index'), false);

        $this->actingAs($marta)->get(route('jornada.incidencia.crear'))
            ->assertRedirect(route('incidencias.index'));

        $this->actingAs($marta)->get(route('incidencias.index'))
            ->assertOk()
            ->assertSee('Comunicar incidencia', false)
            ->assertSee('name="destinatario"', false)
            ->assertSee('Ana López', false)
            ->assertSee('Lucía Vega', false)
            ->assertSee('10 a 500 caracteres', false);

        $this->actingAs($marta)->post(route('jornada.incidencia'), [
            'motivo' => 'Revisa el fichaje del lunes',
        ])->assertSessionHasErrors('destinatario');

        $this->actingAs($marta)->post(route('jornada.incidencia'), [
            'motivo' => 'Revisa el fichaje del lunes',
            'destinatario' => $otraJefa->id,
        ])->assertSessionHasErrors('destinatario');

        $this->actingAs($marta)->post(route('jornada.incidencia'), [
            'motivo' => 'Revisa el fichaje del lunes',
            'destinatario' => $ana->id,
        ])->assertRedirect(route('incidencias.index'))
            ->assertSessionHas('ok', 'Enviada. Ana López ya tiene tu incidencia.');

        $this->assertSame(0, Ticket::query()->count());

        $incidencia = Incidencia::query()->where('destinatario_id', $ana->id)->firstOrFail();
        $this->assertSame($marta->id, $incidencia->user_id);
        $this->assertNull($incidencia->vista_at);

        $this->actingAs($marta)->get(route('jornada'))
            ->assertDontSee('aviso-ticket', false);

        $this->actingAs($ana)->get(route('jornada'))
            ->assertSee('aviso-ticket', false)
            ->assertSee('Incidencia, 1 nueva', false);

        $this->actingAs($lucia)->post(route('incidencias.mensaje', $incidencia), [
            'cuerpo' => 'No es mía',
        ])->assertForbidden();

        $this->actingAs($ana)
            ->get(route('jornada.incidencia.crear', ['incidencia' => $incidencia->id]))
            ->assertSee('De Marta Ruiz', false)
            ->assertSee('Revisa el fichaje del lunes', false);

        $this->assertNotNull($incidencia->fresh()->vista_at);

        $this->actingAs($ana)->get(route('jornada'))
            ->assertDontSee('aviso-ticket', false);

        $this->actingAs($ana)->post(route('incidencias.mensaje', $incidencia), [
            'cuerpo' => 'Ya lo reviso',
        ])->assertSessionHas('ok');

        $this->actingAs($marta)->get(route('incidencias.index'))
            ->assertSee('Para Ana López', false)
            ->assertSee('Revisa el fichaje del lunes', false);

        $this->actingAs($marta)->get(route('incidencias.show', $incidencia))
            ->assertSee('Ya lo reviso', false)
            ->assertSee('Para Ana López', false);

        $this->assertNotNull($incidencia->fresh()->vista_at);
    }

    public function test_la_jefa_filtra_incidencias_por_un_rango_de_fechas(): void
    {
        $marta = $this->persona('Marta Ruiz', 'marta.ruiz@abaco.test', 'Madrid', 'jefa');
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        Incidencia::create(['user_id' => $ana->id, 'cuerpo' => 'La de ayer'])
            ->forceFill(['created_at' => '2026-10-06 09:00:00'])
            ->save();
        Incidencia::create(['user_id' => $ana->id, 'cuerpo' => 'La de hoy'])
            ->forceFill(['created_at' => '2026-10-07 10:00:00'])
            ->save();

        $this->actingAs($marta)
            ->get(route('incidencias.index', ['desde' => '2026-10-07', 'hasta' => '2026-10-07']))
            ->assertOk()
            ->assertSee('La de hoy', false)
            ->assertDontSee('La de ayer', false)
            ->assertSee('>Hoy<', false);

        $this->actingAs($marta)
            ->get(route('incidencias.index', ['desde' => '2026-10-06', 'hasta' => '2026-10-07']))
            ->assertSee('La de hoy', false)
            ->assertSee('La de ayer', false);
    }

    public function test_la_jefa_ve_un_aviso_cuando_hay_una_incidencia_nueva(): void
    {
        $marta = $this->persona('Marta Ruiz', 'marta.ruiz@abaco.test', 'Madrid', 'jefa');
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');

        $this->actingAs($marta)->get(route('jornada'))
            ->assertOk()
            ->assertDontSee('aviso-ticket', false);

        $incidencia = Incidencia::create([
            'user_id' => $ana->id,
            'cuerpo' => 'Se cayó la red del centro.',
        ]);

        $this->actingAs($marta)->get(route('jornada'))
            ->assertSee('aviso-ticket', false)
            ->assertSee('Incidencias, 1 nueva', false);

        $this->actingAs($ana)->get(route('jornada'))
            ->assertDontSee('aviso-ticket', false);

        $this->actingAs($marta)->get(route('incidencias.show', $incidencia))->assertOk();

        $this->actingAs($marta)->get(route('jornada'))
            ->assertDontSee('aviso-ticket', false);
    }

    public function test_la_salida_guarda_quien_la_declaro_y_una_correccion_no_lo_borra(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);

        $this->actingAs($ana)->post(route('jornada.entrada'))->assertSessionHas('ok');
        $this->actingAs($ana)->post(route('jornada.salida'))->assertSessionHas('ok');

        $tramo = Tramo::query()->first();
        $this->assertSame($ana->id, $tramo->cerrado_por);
        $this->assertSame('10:45', $tramo->cerrado_at->timezone('Europe/Madrid')->format('H:i'));

        Carbon::setTestNow(Carbon::parse('2026-10-07 11:30:00', 'Europe/Madrid'));
        $this->actingAs($ana)->post(route('tramos.corregir', $tramo), [
            'campo' => 'ended_at',
            'hora' => '11:00',
            'motivo' => 'hora',
        ])->assertSessionHas('ok');

        $fresco = $tramo->fresh();
        $this->assertSame('11:00', $fresco->ended_at->timezone('Europe/Madrid')->format('H:i'));
        $this->assertSame($ana->id, $fresco->cerrado_por);
        $this->assertSame('10:45', $fresco->cerrado_at->timezone('Europe/Madrid')->format('H:i'));

        $this->actingAs($ana)
            ->get(route('registro.show', $fresco->jornada_id))
            ->assertSee('Salida declarada por Ana López', false)
            ->assertSee('a las 10:45', false);
    }

    public function test_se_puede_corregir_un_tramo_aunque_se_cruce_con_otro(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $jornada = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-07']);
        $tramo = Tramo::create([
            'jornada_id' => $jornada->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-07 09:00:00',
            'ended_at' => '2026-10-07 12:00:00',
        ]);
        Tramo::create([
            'jornada_id' => $jornada->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-07 13:00:00',
            'ended_at' => '2026-10-07 18:00:00',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-07 19:00:00', 'Europe/Madrid'));

        $this->actingAs($ana)
            ->post(route('tramos.corregir', $tramo), [
                'campo' => 'ended_at',
                'hora' => '14:00',
                'motivo' => 'hora',
            ])
            ->assertSessionHas('ok');

        $this->assertSame('14:00', $tramo->fresh()->ended_at->timezone('Europe/Madrid')->format('H:i'));
    }

    public function test_el_registro_no_se_queda_en_sesenta_jornadas(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $inicio = Carbon::parse('2026-01-01', 'Europe/Madrid');

        for ($i = 0; $i < 61; $i++) {
            Jornada::create([
                'user_id' => $ana->id,
                'work_date' => $inicio->copy()->addDays($i)->toDateString(),
            ]);
        }

        $this->actingAs($ana)
            ->get(route('registro', ['periodo' => 'todo']))
            ->assertSee('Lunes, 2 mar', false)
            ->assertDontSee('Jueves, 1 ene', false)
            ->assertSee('Días anteriores', false);

        $this->actingAs($ana)
            ->get(route('registro', ['desde' => '2026-01-01', 'hasta' => '2026-03-02']))
            ->assertSee('Lunes, 2 mar', false)
            ->assertDontSee('Jueves, 1 ene', false)
            ->assertSee('Días anteriores', false);

        $this->actingAs($ana)
            ->get(route('registro', ['desde' => '2026-01-01', 'hasta' => '2026-03-02', 'page' => 2]))
            ->assertSee('Jueves, 1 ene', false);

        $this->actingAs($ana)
            ->get(route('registro', ['desde' => '2026-01-01', 'hasta' => '2026-01-01']))
            ->assertSee('Jueves, 1 ene', false)
            ->assertDontSee('Viernes, 2 ene', false);
    }

    public function test_la_tabla_y_el_excel_usan_el_rango_y_marcan_la_incidencia(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        $normal = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-06']);
        Tramo::create([
            'jornada_id' => $normal->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-06 09:00:00',
            'ended_at' => '2026-10-06 18:00:00',
        ]);
        $incidencia = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-07']);
        Tramo::create([
            'jornada_id' => $incidencia->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-07 09:00:00',
            'ended_at' => '2026-10-07 10:30:00',
            'situacion' => 'incidencia',
        ]);

        $this->actingAs($ana)
            ->get(route('registro', ['desde' => '2026-10-07', 'hasta' => '2026-10-07']))
            ->assertOk()
            ->assertSee('Incidencia', false)
            ->assertSee('Con incidencias', false)
            ->assertDontSee('>Sí<', false)
            ->assertSee('Miércoles, 7 oct', false)
            ->assertDontSee('Martes, 6 oct', false);

        $this->actingAs($ana)
            ->get(route('registro', ['desde' => '2026-10-07', 'hasta' => '2026-10-07', 'incidencia' => 'si']))
            ->assertSee('Miércoles, 7 oct', false)
            ->assertSee('Incidencia', false);

        $this->actingAs($ana)
            ->get(route('registro', ['desde' => '2026-10-06', 'hasta' => '2026-10-07', 'incidencia' => 'no']))
            ->assertSee('Martes, 6 oct', false)
            ->assertDontSee('09:00 – 10:30', false);

        $copia = $this->actingAs($ana)
            ->get(route('registro.csv', ['desde' => '2026-10-07', 'hasta' => '2026-10-07']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Fecha;Inicio;Fin;Total;Estado;Incidencia', $copia);
        $this->assertStringContainsString('"miércoles 7 de octubre";09:00;10:30;"1 h 30 min";Cerrada;Sí', $copia);
        $this->assertStringNotContainsString('martes 6 de octubre', $copia);
    }

    public function test_el_calendario_marca_hoy_la_ausencia_y_abre_un_dia_pasado(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $jornada = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-06']);
        Ausencia::create([
            'user_id' => $ana->id,
            'absence_date' => '2026-10-20',
            'tipo' => 'vacaciones',
        ]);
        Ausencia::create([
            'user_id' => $this->persona('Nuria Vega', 'nuria.vega@abaco.test', 'Madrid')->id,
            'absence_date' => '2026-11-03',
            'tipo' => 'permiso',
        ]);

        $this->actingAs($ana)
            ->get(route('calendario'))
            ->assertOk()
            ->assertSee('casilla-hoy', false)
            ->assertSee('casilla-vacaciones', false)
            ->assertDontSee('/registro/'.$jornada->id, false)
            ->assertDontSee('Nuria Vega', false)
            ->assertDontSee('casilla-permiso', false)
            ->assertDontSee('El calendario de 2027 no está cargado', false)
            ->assertSee('Mes siguiente', false);

        $this->actingAs($ana)
            ->get(route('calendario', ['anio' => 2027]))
            ->assertOk()
            ->assertSee('enero 2027', false)
            ->assertSee('El calendario de 2027 no está cargado', false);
    }

    public function test_el_calendario_no_muestra_faltas(): void
    {
        $marta = $this->persona('Marta Ruiz', 'marta.ruiz@abaco.test', 'Madrid', 'jefa');
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->confirmarSaldoPrueba($marta, $marta);
        Ausencia::create([
            'user_id' => $ana->id,
            'absence_date' => '2026-10-20',
            'tipo' => 'vacaciones',
        ]);

        $this->actingAs($marta)
            ->get(route('calendario'))
            ->assertOk()
            ->assertDontSee('1 falta', false)
            ->assertDontSee('Quién falta', false)
            ->assertDontSee('Fallo común', false)
            ->assertSee('Solicitar vacaciones', false)
            ->assertSee('vacaciones-total">22', false);
    }

    public function test_el_equipo_pide_vacaciones_y_la_jefa_las_decide_en_gestion(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $marta = $this->persona('Marta Ruiz', 'marta.ruiz@abaco.test', 'Madrid', 'jefa');
        $carmen = $this->persona('Carmen Ortega', 'carmen.ortega@abaco.test', 'Madrid', 'tecnico');
        $this->confirmarSaldoPrueba($ana, $marta);
        $this->confirmarSaldoPrueba($marta, $marta);

        $this->actingAs($ana)->get(route('calendario'))
            ->assertSee('Solicitar vacaciones', false)
            ->assertSee('Elige un rango de fechas', false)
            ->assertSee('vacaciones-total">22', false);
        $this->actingAs($carmen)->get(route('calendario'))->assertSee('Solicitar vacaciones', false);
        $this->actingAs($marta)->get(route('calendario'))->assertSee('Solicitar vacaciones', false);

        $this->actingAs($ana)->post(route('calendario.vacaciones'), [
            'desde' => '2026-10-20',
            'hasta' => '2026-10-22',
            'nota' => 'Viaje familiar',
        ])->assertRedirect()->assertSessionHas('ok');

        $solicitud = SolicitudVacaciones::query()->where('user_id', $ana->id)->firstOrFail();
        $this->assertSame(SolicitudVacaciones::PENDIENTE, $solicitud->estado);
        $this->actingAs($ana)->get(route('calendario'))->assertSee('casilla-pendiente', false);

        $this->actingAs($marta)->get(route('jornada'))
            ->assertDontSee('Consultas, 1 nueva', false)
            ->assertDontSee('Solicitud de vacaciones', false);

        $this->actingAs($ana)->post(route('jefe.vacaciones.decidir', $solicitud), [
            'decision' => 'aprobada',
        ])->assertRedirect(route('jornada'));
        $this->assertSame(SolicitudVacaciones::PENDIENTE, $solicitud->fresh()->estado);

        $this->actingAs($marta)->get(route('gestion.index'))
            ->assertOk()
            ->assertSee('Solicitudes de vacaciones', false)
            ->assertSee(route('gestion.cobertura'), false)
            ->assertDontSee('Ana López', false)
            ->assertDontSee('Aprobar solicitud', false);

        $this->actingAs($marta)->get(route('gestion.cobertura'))
            ->assertOk()
            ->assertSee('Ana López', false)
            ->assertSee('Del 20/10/2026 al 22/10/2026', false)
            ->assertDontSee('Viaje familiar', false)
            ->assertSee('Aprobar solicitud de Ana López', false)
            ->assertSee('Rechazar solicitud de Ana López', false);

        $this->actingAs($marta)->post(route('jefe.vacaciones.decidir', $solicitud), [
            'decision' => 'aprobada',
        ])->assertRedirect()->assertSessionHas('ok');

        $this->assertSame(SolicitudVacaciones::APROBADA, $solicitud->fresh()->estado);
        $this->assertSame(3, Ausencia::query()->where('user_id', $ana->id)->where('tipo', 'vacaciones')->count());
        $this->assertTrue(
            Ausencia::query()->where('user_id', $ana->id)->whereDate('absence_date', '2026-10-20')->exists()
        );

        $this->actingAs($ana)->get(route('calendario'))
            ->assertSee('casilla-vacaciones', false)
            ->assertSee('vacaciones-total">19', false);

        $this->actingAs($marta)->get(route('jornada'))
            ->assertDontSee('Consultas, 1 nueva', false);

        $this->actingAs($ana)->post(route('calendario.vacaciones'), [
            'desde' => '2026-11-02',
            'hasta' => '2026-11-03',
        ])->assertSessionHas('ok');

        $rechazada = SolicitudVacaciones::query()->where('estado', SolicitudVacaciones::PENDIENTE)->firstOrFail();

        $this->actingAs($marta)->post(route('jefe.vacaciones.decidir', $rechazada), [
            'decision' => 'rechazada',
        ])->assertSessionHas('ok');

        $this->assertSame(SolicitudVacaciones::RECHAZADA, $rechazada->fresh()->estado);
        $this->assertDatabaseMissing('ausencias', [
            'user_id' => $ana->id,
            'absence_date' => '2026-11-02',
        ]);

        $this->actingAs($ana)->post(route('calendario.vacaciones'), [
            'desde' => '2026-10-21',
            'hasta' => '2026-10-21',
        ])->assertSessionHas('aviso', 'Esas fechas ya están pedidas.');

        $this->actingAs($marta)->post(route('calendario.vacaciones'), [
            'desde' => '2026-12-01',
            'hasta' => '2026-12-02',
        ])->assertRedirect()->assertSessionHas('ok');

        $this->actingAs($ana)->post(route('calendario.vacaciones'), [
            'desde' => '2026-12-08',
            'hasta' => '2026-12-08',
        ])->assertSessionHas('aviso', 'Tienes dos meses para cogerlas. A partir de ahí desaparecen.');
    }

    public function test_el_finde_y_el_festivo_no_gastan_vacaciones(): void
    {
        $this->festivosPublicos([
            [
                'date' => '2026-10-26',
                'localName' => 'Puente',
                'global' => true,
                'counties' => null,
                'types' => ['Public'],
            ],
        ]);
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $marta = $this->persona('Marta Ruiz', 'marta.ruiz@abaco.test', 'Madrid', 'jefa');
        $this->confirmarSaldoPrueba($ana, $marta);

        $this->actingAs($ana)->post(route('calendario.vacaciones'), [
            'desde' => '2026-10-24',
            'hasta' => '2026-10-25',
        ])->assertSessionHas('aviso', 'Esos días no cuentan: son fin de semana o festivo.');

        $this->actingAs($ana)->post(route('calendario.vacaciones'), [
            'desde' => '2026-10-23',
            'hasta' => '2026-10-26',
        ])->assertSessionHas('ok');

        $this->actingAs($ana)->get(route('calendario'))
            ->assertSee('data-fecha="2026-10-23" data-cuenta="1"', false)
            ->assertSee('casilla-pendiente', false)
            ->assertDontSee('casilla-finde casilla-pendiente', false);

        $solicitud = SolicitudVacaciones::query()->where('user_id', $ana->id)->firstOrFail();
        $this->actingAs($marta)->post(route('jefe.vacaciones.decidir', $solicitud), [
            'decision' => 'aprobada',
        ])->assertSessionHas('ok');

        $this->assertSame(['2026-10-23'], Ausencia::query()->where('user_id', $ana->id)->pluck('absence_date')->map(fn ($fecha) => substr((string) $fecha, 0, 10))->all());
        $this->actingAs($ana)->get(route('calendario'))->assertSee('vacaciones-total">21', false);
    }

    public function test_un_festivo_en_domingo_no_se_traslada_automaticamente(): void
    {
        $this->festivosPublicos([
            [
                'date' => '2026-11-01',
                'localName' => 'Día de todos los Santos',
                'global' => true,
                'counties' => null,
                'types' => ['Public'],
            ],
            [
                'date' => '2026-12-06',
                'localName' => 'Día de la Constitución',
                'global' => true,
                'counties' => null,
                'types' => ['Public'],
            ],
            [
                'date' => '2026-08-15',
                'localName' => 'Asunción',
                'global' => true,
                'counties' => null,
                'types' => ['Public'],
            ],
        ]);
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        $festivos = app(NagerFestivos::class)->delAnio('Madrid', 2026);

        $this->assertSame(['2026-08-15', '2026-11-01', '2026-12-06'], $festivos->map(fn ($dia) => $dia->holiday_date->toDateString())->all());

        Carbon::setTestNow(Carbon::parse('2026-11-01 10:30:00', 'Europe/Madrid'));
        $this->actingAs($ana)->get(route('jornada'))->assertSee('Hoy es festivo en tu centro', false);

        Carbon::setTestNow(Carbon::parse('2026-11-02 10:30:00', 'Europe/Madrid'));
        $this->actingAs($ana)
            ->get(route('jornada'))
            ->assertDontSee('Hoy es festivo en tu centro', false);

        $this->actingAs($ana)
            ->get(route('calendario'))
            ->assertSee('casilla-estado', false)
            ->assertSee('Día de todos los Santos', false)
            ->assertDontSee('Día de la Constitución', false);

        $this->actingAs($ana)
            ->get(route('calendario', ['mes' => 12]))
            ->assertSee('casilla-estado', false)
            ->assertSee('Día de la Constitución', false);
    }

    public function test_ver_el_calendario_no_borra_la_jornada(): void
    {
        $this->festivosPublicos($this->muestraNager());
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-04-02']);

        $this->actingAs($ana)
            ->get(route('calendario', ['mes' => 4]))
            ->assertOk()
            ->assertSee('casilla-comunidad', false)
            ->assertSee('Jueves Santo', false);

        $this->assertNotNull(Jornada::query()->where('user_id', $ana->id)->whereDate('work_date', '2026-04-02')->first());
    }

    public function test_el_alta_pide_la_sede_y_no_impone_madrid(): void
    {
        $marta = $this->persona('Marta Ruiz', 'marta.ruiz@abaco.test', 'Madrid', 'jefa');

        $this->actingAs($marta)
            ->get(route('equipo.create'))
            ->assertOk()
            ->assertSee('Sede municipal', false)
            ->assertDontSee('Municipio del centro', false);

        $this->actingAs($marta)
            ->post(route('equipo.store'), [
                'name' => 'Nuria Vega',
                'email' => 'nuria.vega@abaco.test',
                'password' => 'Jornada2026',
                'starts_on' => '2026-10-13',
                'duracion' => 'indefinido',
                'centro' => 'Sevilla',
                'tipo' => 'partida',
                'morning_start' => '09:00',
                'morning_end' => '14:00',
                'afternoon_start' => '15:00',
                'afternoon_end' => '18:00',
            ])
            ->assertSessionHas('ok');

        $alta = User::query()->where('email', 'nuria.vega@abaco.test')->first();
        $this->assertSame('Sevilla', $alta->municipality);
        $this->assertSame('Sevilla', $alta->sede->name);
    }

    public function test_la_pantalla_de_hoy_trae_los_tramos_cargados_y_el_horario(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        $jornada = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-07']);
        Tramo::create([
            'jornada_id' => $jornada->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-07 09:00:00',
            'ended_at' => '2026-10-07 10:00:00',
        ]);

        $datos = app(JornadaService::class)->pantalla($ana);

        $this->assertCount(1, $datos['tramos']);
        $this->assertSame('09:00', $datos['tramos']->first()->started_at->timezone('Europe/Madrid')->format('H:i'));
        $this->assertSame([
            'mananaInicio' => '09:00',
            'mananaFin' => '14:00',
            'tardeInicio' => '15:00',
            'tardeFin' => '18:00',
        ], $datos['horarioHoy']);
    }

    public function test_el_calendario_desconocido_no_crea_avisos_ni_impide_fichar_trabajo_real(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        ImportacionCalendario::query()->where('centro_id', $ana->centro_id)->delete();
        Carbon::setTestNow(Carbon::parse('2026-10-30 10:45:00', 'Europe/Madrid'));
        $anterior = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-28']);
        Tramo::create([
            'jornada_id' => $anterior->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-28 09:00:00',
            'ended_at' => '2026-10-28 18:00:00',
        ]);
        $servicio = app(JornadaService::class);

        $respuesta = $this->actingAs($ana)->get(route('jornada'))
            ->assertOk()
            ->assertSee('El calendario de tu centro para 2026 está pendiente de confirmar.')
            ->assertSee('Puedes fichar el trabajo real.')
            ->assertSee('Los avisos de inicio están en pausa.')
            ->assertSee('Ver calendario')
            ->assertDontSee('Cargar calendario')
            ->assertViewHas('puedeEntrar', true);

        $this->assertSame('desconocido', $respuesta->viewData('expectativa')['estado']);
        $this->assertSame('calendario', $servicio->expectativaDe($ana, now())['motivo']);
        $this->assertFalse($servicio->esDiaEsperado($ana, now()));
        $this->assertDatabaseCount('avisos', 0);
        $this->actingAs($ana)->post(route('jornada.entrada'))->assertSessionHas('ok');
        $this->assertModelExists(Jornada::query()->where('user_id', $ana->id)->whereDate('work_date', '2026-10-30')->firstOrFail());
        $this->assertDatabaseCount('avisos', 0);
    }

    public function test_un_festivo_manual_confirmado_exime_aunque_falte_el_resto_del_calendario(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        ImportacionCalendario::query()->where('centro_id', $ana->centro_id)->delete();
        Festivo::create([
            'holiday_date' => '2026-10-07',
            'name' => 'Fiesta local confirmada',
            'ambito' => 'pueblo',
            'municipality' => 'Madrid',
            'centro_id' => $ana->centro_id,
        ]);

        $expectativa = app(JornadaService::class)->expectativaDe($ana, now());

        $this->assertSame('exento', $expectativa['estado']);
        $this->assertSame('festivo', $expectativa['motivo']);
    }

    public function test_una_ausencia_aprobada_exime_aunque_no_este_confirmado_el_calendario(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        ImportacionCalendario::query()->where('centro_id', $ana->centro_id)->delete();
        Ausencia::create(['user_id' => $ana->id, 'absence_date' => '2026-10-07', 'tipo' => 'vacaciones']);

        $expectativa = app(JornadaService::class)->expectativaDe($ana, now());

        $this->assertSame('exento', $expectativa['estado']);
        $this->assertSame('ausencia', $expectativa['motivo']);
    }

    public function test_un_aviso_de_inicio_no_bloquea_si_se_confirma_despues_un_festivo(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        Carbon::setTestNow(Carbon::parse('2026-10-30 10:45:00', 'Europe/Madrid'));
        $aviso = Aviso::create(['user_id' => $ana->id, 'tipo' => 'inicio', 'work_date' => '2026-10-29', 'cuenta' => true]);
        Festivo::create([
            'holiday_date' => '2026-10-29',
            'name' => 'Fiesta local confirmada',
            'ambito' => 'pueblo',
            'municipality' => 'Madrid',
            'centro_id' => $ana->centro_id,
        ]);

        $this->actingAs($ana)->post(route('jornada.entrada'))->assertSessionHas('ok');

        $this->assertModelExists($aviso);
        $this->assertModelExists(Jornada::query()->where('user_id', $ana->id)->whereDate('work_date', '2026-10-30')->firstOrFail());
        $this->assertCount(0, app(JornadaService::class)->avisosSinExplicar($ana, now()));
    }

    public function test_un_dia_de_registro_con_fecha_imposible_devuelve_404(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');

        $this->actingAs($ana)
            ->get(route('registro.dia', '2026-99-99'))
            ->assertNotFound();
    }

    public function test_un_horario_pendiente_se_explica_sin_impedir_fichar_trabajo_real(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');

        $this->actingAs($ana)->get(route('jornada'))
            ->assertOk()
            ->assertSee('Tu horario está pendiente de asignar.')
            ->assertSee('Puedes fichar el trabajo real.')
            ->assertSee('Los avisos de inicio están en pausa.')
            ->assertDontSee('El calendario de tu centro para 2026 está pendiente de confirmar.')
            ->assertViewHas('puedeEntrar', true);

        $this->actingAs($ana)->post(route('jornada.entrada'))->assertSessionHas('ok');
        $this->assertDatabaseCount('jornadas', 1);
        $this->assertDatabaseCount('avisos', 0);
    }

    public function test_la_jefa_conserva_un_solo_aviso_para_cargar_el_calendario(): void
    {
        $marta = $this->persona('Marta Ruiz', 'marta.ruiz@abaco.test', 'Madrid', 'jefa');
        $this->horario($marta);
        ImportacionCalendario::query()->where('centro_id', $marta->centro_id)->delete();

        $respuesta = $this->actingAs($marta)->get(route('jornada'))
            ->assertOk()
            ->assertSee('El calendario de 2026 no está cargado.')
            ->assertSee('Cargar calendario')
            ->assertDontSee('Los avisos de inicio están en pausa.')
            ->assertViewHas('puedeEntrar', true);

        $this->assertSame(1, substr_count($respuesta->getContent(), 'El calendario de 2026 no está cargado.'));
    }

    public function test_el_calendario_avisa_una_sola_vez_en_mes_y_anio_hasta_confirmar_cobertura(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        Festivo::create([
            'holiday_date' => '2027-05-15',
            'name' => 'Festivo local conocido',
            'ambito' => 'pueblo',
            'municipality' => 'Madrid',
            'centro_id' => $ana->centro_id,
        ]);

        foreach (['mes', 'anio'] as $vista) {
            $respuesta = $this->actingAs($ana)->get(route('calendario', ['anio' => 2027, 'mes' => 5, 'vista' => $vista, 'fragmento' => 1]))
                ->assertOk()
                ->assertViewHas('sinCalendario', true)
                ->assertSee('El calendario de 2027 no está cargado.');
            $this->assertSame(1, substr_count($respuesta->getContent(), 'El calendario de 2027 no está cargado.'));
        }

        $this->calendarioConfirmado($ana->sede, 2027);
        foreach (['mes', 'anio'] as $vista) {
            $this->actingAs($ana)->get(route('calendario', ['anio' => 2027, 'mes' => 5, 'vista' => $vista]))
                ->assertOk()
                ->assertViewHas('sinCalendario', false)
                ->assertDontSee('El calendario de 2027 no está cargado.');
        }
    }

    private function confirmarSaldoPrueba(User $persona, ?User $responsable = null): void
    {
        $responsable ??= User::factory()->jefa()->create();
        $cupo = app(CupoVacaciones::class);
        app(AjustarSaldoVacaciones::class)->ajustar($responsable, $persona, 2026, 22,
            $cupo->aprobados($persona, 2026), 0, 'Saldo explícito de la prueba', (string) Str::uuid());
    }

    private function persona(string $nombre, string $correo, string $municipio, string $papel = 'trabajadora'): User
    {
        $this->calendarioConfirmado(Centro::query()->where('name', 'Madrid')->firstOrFail(), 2026);

        return User::create([
            'name' => $nombre,
            'email' => $correo,
            'password' => 'Jornada2026',
            'role' => $papel,
            'active' => true,
            'starts_on' => '2026-01-07',
            'municipality' => $municipio,
            'centro_id' => Centro::query()->where('name', 'Madrid')->value('id'),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function muestraNager(): array
    {
        return [
            [
                'date' => '2026-01-01',
                'localName' => 'Año Nuevo',
                'global' => true,
                'counties' => null,
                'types' => ['Public'],
            ],
            [
                'date' => '2026-02-28',
                'localName' => 'Día de Andalucía',
                'global' => false,
                'counties' => ['ES-AN'],
                'types' => ['Public'],
            ],
            [
                'date' => '2026-04-02',
                'localName' => 'Jueves Santo',
                'global' => false,
                'counties' => ['ES-MD'],
                'types' => ['Public'],
            ],
            [
                'date' => '2026-06-24',
                'localName' => 'Solo observancia',
                'global' => true,
                'counties' => null,
                'types' => ['Observance'],
            ],
        ];
    }

    private function horario(User $user): void
    {
        $this->calendarioConfirmado($user->sede, 2026);

        Horario::create([
            'user_id' => $user->id,
            'morning_start' => '09:00',
            'morning_end' => '14:00',
            'afternoon_start' => '15:00',
            'afternoon_end' => '18:00',
            'effective_from' => '2026-01-07',
        ]);
    }
}
