<?php

namespace Tests\Feature;

use App\Models\Aviso;
use App\Models\Centro;
use App\Models\Consulta;
use App\Models\Correccion;
use App\Models\Festivo;
use App\Models\Horario;
use App\Models\ImportacionCalendario;
use App\Models\Jornada;
use App\Models\SolicitudVacaciones;
use App\Models\Tramo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Funciones de dirección que ahora hace el responsable: estado del equipo,
 * registros con motivo, resumen mensual, copia CSV y lista de consultas.
 */
class JefeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-07 10:45:00', 'Europe/Madrid'));
    }

    public function test_la_jefa_entra_en_hoy_ve_el_estado_y_ficha_como_cualquiera(): void
    {
        $jefa = $this->jefa();

        $this->post(route('login'), ['email' => $jefa->email, 'password' => 'password'])
            ->assertRedirect(route('jornada'));

        $this->actingAs($jefa)
            ->get(route('jefe.index'))
            ->assertOk()
            ->assertSee('Estado del equipo');

        $this->horario($jefa);
        $this->actingAs($jefa)->post(route('jornada.entrada'));

        $this->assertDatabaseHas('jornadas', ['user_id' => $jefa->id]);
    }

    public function test_el_equipo_muestra_ocho_personas_y_permite_ver_todas(): void
    {
        $jefa = $this->jefa();
        foreach (range(1, 8) as $numero) {
            $this->persona('Trabajador '.$numero, 'trabajador'.$numero.'@abaco.test');
        }

        $this->actingAs($jefa)
            ->get(route('equipo.index'))
            ->assertOk()
            ->assertSee('Trabajador 5', false)
            ->assertSee('Trabajador 6', false)
            ->assertDontSee('Trabajador 8', false)
            ->assertSee('Todos los trabajadores', false);

        $this->actingAs($jefa)
            ->get(route('equipo.index', ['todas_personas' => 1]))
            ->assertSee('Trabajador 6', false)
            ->assertSee('Volver', false);
    }

    public function test_la_ficha_del_equipo_enlaza_a_la_gestion_de_horario_y_vacaciones(): void
    {
        $jefe = $this->jefa();
        $persona = $this->persona('Persona de prueba', 'persona@abaco.test');

        $this->actingAs($jefe)->get(route('equipo.index'))->assertOk()
            ->assertSee('Gestionar horario y vacaciones')
            ->assertSee('href="'.route('equipo.show', $persona).'"', false);
        $this->get(route('equipo.show', $persona))->assertOk()->assertSee('Saldo de vacaciones');
        $this->actingAs($persona)->get(route('equipo.show', $persona))->assertRedirect(route('jornada'));
    }

    public function test_el_panel_enseña_estado_y_horario_sin_crear_avisos(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test');
        $lucia = $this->persona('Lucía Vega', 'lucia.vega@abaco.test');
        $this->horario($ana);
        $this->horario($lucia);
        $jornada = Jornada::create(['user_id' => $lucia->id, 'work_date' => '2026-10-07']);
        Tramo::create([
            'jornada_id' => $jornada->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-07 09:00:00',
        ]);

        $respuesta = $this->actingAs($this->jefa())
            ->get(route('jefe.index'))
            ->assertOk()
            ->assertSee('Todavía no ha empezado')
            ->assertSee('Abierta')
            ->assertSee('09:00–14:00 y 15:00–18:00');

        $this->assertCount(3, $respuesta->viewData('personas'));
        $this->assertDatabaseCount('avisos', 0);
        $this->assertDatabaseCount('jornadas', 1);
    }

    public function test_la_jefa_ve_marcados_los_cierres_sin_resolver_de_mas_de_siete_dias(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test');
        $lucia = $this->persona('Lucía Vega', 'lucia.vega@abaco.test');
        $jefa = $this->jefa();

        $this->actingAs($jefa)->get(route('jefe.index'))->assertDontSee('sin resolver de hace más de', false);

        Aviso::create(['user_id' => $ana->id, 'tipo' => 'cierre', 'work_date' => '2026-09-20', 'cuenta' => true]);
        Aviso::create(['user_id' => $ana->id, 'tipo' => 'cierre', 'work_date' => '2026-09-25', 'cuenta' => true]);
        Aviso::create(['user_id' => $ana->id, 'tipo' => 'cierre', 'work_date' => '2026-09-26', 'cuenta' => true, 'responded_at' => '2026-09-27 10:00:00']);
        Aviso::create(['user_id' => $ana->id, 'tipo' => 'cierre', 'work_date' => '2026-09-27', 'cuenta' => false, 'exclusion' => 'comun']);
        Aviso::create(['user_id' => $ana->id, 'tipo' => 'cierre', 'work_date' => '2026-10-05', 'cuenta' => true]);
        Aviso::create(['user_id' => $lucia->id, 'tipo' => 'cierre', 'work_date' => '2026-10-05', 'cuenta' => true]);

        $respuesta = $this->actingAs($jefa)
            ->get(route('jefe.index'))
            ->assertOk()
            ->assertSee('Ana López', false)
            ->assertSee('1 persona tiene', false)
            ->assertSee('2 cierres sin resolver', false)
            ->assertSee('de hace más de 7 días', false);

        $this->assertSame(2, $respuesta->viewData('personas')->firstWhere('persona.id', $ana->id)['antiguos']);
        $this->assertSame(0, $respuesta->viewData('personas')->firstWhere('persona.id', $lucia->id)['antiguos']);

        $this->actingAs($jefa)->get(route('jefe.persona', $ana))->assertSee('Tiene 2 cierres sin resolver', false);
        $this->actingAs($jefa)->get(route('jefe.persona', $lucia))->assertDontSee('sin resolver de hace más de', false);
        $this->assertDatabaseCount('avisos', 6);
    }

    public function test_la_jefa_no_cuenta_cierres_del_anio_anterior(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test');
        $jefa = $this->jefa();

        Aviso::create(['user_id' => $ana->id, 'tipo' => 'cierre', 'work_date' => '2025-12-15', 'cuenta' => true]);
        Aviso::create(['user_id' => $ana->id, 'tipo' => 'cierre', 'work_date' => '2026-09-20', 'cuenta' => true]);

        $respuesta = $this->actingAs($jefa)
            ->get(route('jefe.index'))
            ->assertOk()
            ->assertSee('1 persona tiene', false)
            ->assertSee('1 cierre sin resolver', false);

        $this->assertSame(1, $respuesta->viewData('personas')->firstWhere('persona.id', $ana->id)['antiguos']);
        $this->assertDatabaseCount('avisos', 2);
    }

    public function test_solo_la_jefa_entra_en_las_pantallas_de_direccion(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test');
        $tecnico = $this->persona('Carmen Ortega', 'carmen.ortega@abaco.test', 'tecnico');

        foreach ([$ana, $tecnico] as $persona) {
            $this->actingAs($persona)->get(route('jefe.index'))->assertRedirect(route('jornada'));
            $this->actingAs($persona)->get(route('jefe.registro', $ana))->assertRedirect(route('jornada'));
            $this->actingAs($persona)->get(route('jefe.consultas'))->assertRedirect(route('jornada'));
            $this->actingAs($persona)->get(route('gestion.index'))->assertRedirect(route('jornada'));
        }

        $this->assertDatabaseCount('consultas', 0);
    }

    public function test_el_registro_pide_motivo_y_la_consulta_queda_escrita(): void
    {
        $jefa = $this->jefa();
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test');
        Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-06']);

        $this->actingAs($jefa)
            ->get(route('jefe.registro', $ana))
            ->assertSee('hace falta un motivo')
            ->assertDontSee('Descargar copia');

        $this->actingAs($jefa)
            ->post(route('jefe.motivo', $ana), ['reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->assertDatabaseCount('consultas', 0);

        $this->actingAs($jefa)
            ->post(route('jefe.motivo', $ana), ['reason' => 'Revisión del artículo 34.9'])
            ->assertRedirect(route('jefe.registro', $ana));

        $this->actingAs($jefa)
            ->get(route('jefe.registro', $ana))
            ->assertOk()
            ->assertSee('Descargar copia');

        $this->assertDatabaseHas('consultas', [
            'viewer_id' => $jefa->id,
            'subject_id' => $ana->id,
            'reason' => 'Revisión del artículo 34.9',
        ]);
    }

    public function test_el_motivo_de_una_persona_no_abre_el_registro_de_otra(): void
    {
        $jefa = $this->jefa();
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test');
        $lucia = $this->persona('Lucía Vega', 'lucia.vega@abaco.test');
        $jornadaDeLucia = Jornada::create(['user_id' => $lucia->id, 'work_date' => '2026-10-06']);

        $this->actingAs($jefa)->post(route('jefe.motivo', $ana), ['reason' => 'Revisión']);

        $this->actingAs($jefa)->get(route('jefe.registro', $lucia))->assertSee('hace falta un motivo');
        $this->actingAs($jefa)->get(route('jefe.dia', $jornadaDeLucia))->assertSee('hace falta un motivo');
        $this->actingAs($jefa)->get(route('jefe.csv', $lucia))->assertSee('hace falta un motivo');
    }

    public function test_el_dia_enseña_la_hora_anterior_y_no_ofrece_cambiarla(): void
    {
        $jefa = $this->jefa();
        $marta = $this->persona('Marta Ruiz', 'marta.ruiz@abaco.test', 'jefa');
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test');
        $jornada = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-06']);
        $tramo = Tramo::create([
            'jornada_id' => $jornada->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-06 09:00:00',
            'ended_at' => '2026-10-06 18:00:00',
        ]);
        Correccion::create([
            'tramo_id' => $tramo->id,
            'user_id' => $marta->id,
            'field' => 'started_at',
            'previous_value' => '2026-10-06 09:30:00',
            'new_value' => '2026-10-06 09:00:00',
            'reason' => 'hora',
        ]);

        $this->actingAs($jefa)->post(route('jefe.motivo', $ana), ['reason' => 'Reclamación']);

        $this->actingAs($jefa)
            ->get(route('jefe.dia', $jornada))
            ->assertOk()
            ->assertSee('Antes 09:30')
            ->assertSee('ahora 09:00')
            ->assertSee('Marta Ruiz')
            ->assertDontSee('Guardar corrección')
            ->assertDontSee(route('tramos.corregir', $tramo), false);

        $this->actingAs($jefa)
            ->get(route('jefe.registro', $ana))
            ->assertSee('con cambios');
    }

    public function test_el_mes_suma_lo_registrado_y_se_puede_elegir(): void
    {
        $jefa = $this->jefa();
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test');
        $this->horario($ana);
        $this->jornadaCerrada($ana, '2026-10-05', '09:00', '18:00');
        $this->jornadaCerrada($ana, '2026-10-06', '09:00', '17:00');

        $this->actingAs($jefa)->post(route('jefe.motivo', $ana), ['reason' => 'Revisión']);

        $respuesta = $this->actingAs($jefa)
            ->get(route('jefe.registro', $ana))
            ->assertSee('Octubre de 2026');

        $this->assertSame(
            ['registrados' => 1020, 'previstos' => 2400, 'porEncima' => 60, 'dias' => 2],
            $respuesta->viewData('resumen'),
        );

        $respuesta
            ->assertSee('Semana del 5 de octubre al 11 de octubre')
            ->assertSee('17 h 0 min');
        $this->assertCount(1, $respuesta->viewData('semanas'));

        $this->actingAs($jefa)
            ->get(route('jefe.registro', [$ana, 'mes' => '2026-09']))
            ->assertSee('Septiembre de 2026')
            ->assertSee('No hay jornadas en este mes.');

        foreach (['hola', '2020-01', '2027-01'] as $mesNoValido) {
            $this->actingAs($jefa)
                ->get(route('jefe.registro', [$ana, 'mes' => $mesNoValido]))
                ->assertSee('Octubre de 2026');
        }
    }

    public function test_un_dia_sin_cerrar_no_sigue_sumando_con_el_reloj(): void
    {
        $jefa = $this->jefa();
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test');
        $this->horario($ana);
        $jornada = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-06']);
        Tramo::create([
            'jornada_id' => $jornada->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-06 09:00:00',
        ]);

        $this->actingAs($jefa)->post(route('jefe.motivo', $ana), ['reason' => 'Revisión']);

        $respuesta = $this->actingAs($jefa)
            ->get(route('jefe.registro', $ana))
            ->assertSee('Pendiente de cerrar');

        $this->assertSame(0, $respuesta->viewData('resumen')['registrados']);

        $this->actingAs($jefa)
            ->get(route('jefe.index'))
            ->assertSee('Tiene un día anterior sin cerrar');
    }

    public function test_el_registro_de_la_jefa_muestra_los_dias_sin_fichaje(): void
    {
        $jefa = $this->jefa();
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test');
        $this->horario($ana);
        Aviso::create([
            'user_id' => $ana->id,
            'tipo' => 'inicio',
            'work_date' => '2026-10-06',
            'cuenta' => true,
        ]);

        $this->actingAs($jefa)->post(route('jefe.motivo', $ana), ['reason' => 'Revisión']);

        $this->actingAs($jefa)
            ->get(route('jefe.registro', $ana))
            ->assertOk()
            ->assertSee('Martes, 6 oct', false)
            ->assertSee('Sin fichaje', false)
            ->assertSee('Sin fichar', false);
    }

    public function test_la_jefa_no_ve_un_dia_sin_fichar_pasados_siete_dias(): void
    {
        $jefa = $this->jefa();
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test');
        $this->horario($ana);
        Carbon::setTestNow(Carbon::parse('2026-10-30 10:45:00', 'Europe/Madrid'));
        Aviso::create([
            'user_id' => $ana->id,
            'tipo' => 'inicio',
            'work_date' => '2026-10-22',
            'cuenta' => true,
        ]);

        $this->actingAs($jefa)
            ->get(route('jefe.index'))
            ->assertOk()
            ->assertDontSee('sin resolver de hace más de', false);

        $this->actingAs($jefa)->post(route('jefe.motivo', $ana), ['reason' => 'Revisión']);

        $this->actingAs($jefa)
            ->get(route('jefe.registro', $ana))
            ->assertOk()
            ->assertDontSee('Sin fichar', false)
            ->assertDontSee('22 oct', false);
    }

    public function test_la_copia_csv_trae_el_historial_y_queda_tras_el_motivo(): void
    {
        $jefa = $this->jefa();
        $marta = $this->persona('Marta Ruiz', 'marta.ruiz@abaco.test', 'jefa');
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test');
        $jornada = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-06']);
        $tramo = Tramo::create([
            'jornada_id' => $jornada->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-06 09:00:00',
            'ended_at' => '2026-10-06 18:00:00',
        ]);
        Correccion::create([
            'tramo_id' => $tramo->id,
            'user_id' => $marta->id,
            'field' => 'started_at',
            'previous_value' => '2026-10-06 09:30:00',
            'new_value' => '2026-10-06 09:00:00',
            'reason' => 'hora',
        ]);

        $this->actingAs($jefa)->post(route('jefe.motivo', $ana), ['reason' => 'Inspección']);

        $copia = $this->actingAs($jefa)->get(route('jefe.csv', $ana))->assertOk()->streamedContent();

        $this->assertStringContainsString('Fecha;Inicio;Fin;Total;Estado;Incidencia', $copia);
        $this->assertStringContainsString('"martes 6 de octubre";09:00;18:00;"9 h 0 min";Cerrada;No', $copia);
        $this->assertStringNotContainsString('09:30', $copia);
    }

    public function test_cada_persona_sigue_descargando_su_copia(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test');
        $this->jornadaCerrada($ana, '2026-10-06', '09:00', '18:00');

        $copia = $this->actingAs($ana)->get(route('registro.csv', ['desde' => '2026-10-06', 'hasta' => '2026-10-06']))->assertOk()->streamedContent();

        $this->assertStringContainsString('"martes 6 de octubre";09:00;18:00', $copia);
    }

    public function test_las_consultas_dicen_quien_miro_y_por_que(): void
    {
        $marta = $this->persona('Marta Ruiz', 'marta.ruiz@abaco.test', 'jefa');
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test');
        Consulta::create(['viewer_id' => $marta->id, 'subject_id' => $ana->id, 'reason' => 'Corregir un cierre']);

        $this->actingAs($this->jefa())
            ->get(route('jefe.consultas'))
            ->assertOk()
            ->assertSee('Marta Ruiz consultó el registro de Ana López')
            ->assertSee('Corregir un cierre');
    }

    public function test_consultas_muestra_tres_y_permite_ver_las_demas(): void
    {
        $jefa = $this->jefa();
        $marta = $this->persona('Marta Ruiz', 'marta.ruiz@abaco.test', 'jefa');
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test');

        foreach (range(1, 5) as $numero) {
            Consulta::create([
                'viewer_id' => $marta->id,
                'subject_id' => $ana->id,
                'reason' => 'Consulta '.$numero,
            ]);
        }

        $this->actingAs($jefa)
            ->get(route('jefe.consultas'))
            ->assertSee('Consulta 5', false)
            ->assertDontSee('Consulta 1', false)
            ->assertSee('Ver todo', false);

        $this->actingAs($jefa)
            ->get(route('jefe.consultas', ['todas' => 1]))
            ->assertSee('Consulta 1', false)
            ->assertSee('Volver', false);
    }

    public function test_solicitudes_de_vacaciones_muestra_tres_y_permite_ver_las_demas(): void
    {
        $jefa = $this->jefa();
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test');

        foreach (range(1, 4) as $numero) {
            SolicitudVacaciones::create([
                'user_id' => $ana->id,
                'starts_on' => Carbon::parse('2026-11-'.$numero),
                'ends_on' => Carbon::parse('2026-11-'.$numero),
                'estado' => SolicitudVacaciones::PENDIENTE,
            ]);
        }

        $this->actingAs($jefa)
            ->get(route('gestion.index'))
            ->assertOk()
            ->assertSee('Solicitudes de vacaciones', false)
            ->assertSee(route('gestion.cobertura'), false)
            ->assertSee('4 por decidir', false)
            ->assertDontSee('El 04/11/2026', false)
            ->assertDontSee('Aprobar solicitud', false);

        $this->actingAs($jefa)
            ->get(route('gestion.cobertura'))
            ->assertOk()
            ->assertSee('El 01/11/2026', false)
            ->assertSee('El 04/11/2026', false);
    }

    public function test_consultas_se_pueden_filtrar_por_persona_fecha_y_mes(): void
    {
        $jefa = $this->jefa();
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test');
        $carmen = $this->persona('Carmen Ortega', 'carmen.ortega@abaco.test');
        $consultaAna = Consulta::create([
            'viewer_id' => $jefa->id,
            'subject_id' => $ana->id,
            'reason' => 'Consulta de Ana',
        ]);
        $consultaCarmen = Consulta::create([
            'viewer_id' => $jefa->id,
            'subject_id' => $carmen->id,
            'reason' => 'Consulta de Carmen',
        ]);
        $consultaAna->forceFill(['created_at' => '2026-09-15 10:00:00'])->save();
        $consultaCarmen->forceFill(['created_at' => '2026-10-02 10:00:00'])->save();

        $this->actingAs($jefa)
            ->get(route('jefe.consultas', ['persona' => 'Carmen']))
            ->assertSee('Consulta de Carmen', false)
            ->assertDontSee('Consulta de Ana', false)
            ->assertSee('Enero de 2026', false)
            ->assertSee('Noviembre de 2026', false)
            ->assertSee('Diciembre de 2026', false);

        $this->actingAs($jefa)
            ->get(route('jefe.consultas', ['desde' => '2026-10-02', 'hasta' => '2026-10-02']))
            ->assertSee('Consulta de Carmen', false)
            ->assertDontSee('Consulta de Ana', false);

        $this->actingAs($jefa)
            ->get(route('jefe.consultas', ['mes' => '2026-09']))
            ->assertSee('Consulta de Ana', false)
            ->assertDontSee('Consulta de Carmen', false);
    }

    public function test_la_jefa_entra_en_gestion_con_vacaciones_sedes_y_festivos(): void
    {
        $jefa = $this->jefa();

        $this->actingAs($jefa)
            ->get(route('gestion.index'))
            ->assertOk()
            ->assertSee('Gestión', false)
            ->assertSee('Solicitudes de vacaciones', false)
            ->assertSee('Sedes', false)
            ->assertSee('Festivos', false);

        $this->actingAs($jefa)
            ->get(route('equipo.index'))
            ->assertDontSee('Gestionar sedes', false);

        $this->actingAs($jefa)
            ->get(route('calendario'))
            ->assertDontSee('Gestionar festivos', false);

        $this->actingAs($jefa)
            ->get(route('jefe.consultas'))
            ->assertDontSee('Solicitudes de vacaciones', false);
    }

    public function test_la_jefa_puede_crear_y_asignar_una_sede_nueva(): void
    {
        $jefa = $this->jefa();
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test');

        $this->actingAs($jefa)
            ->post(route('equipo.centros.store'), [
                'name' => 'Sede Getafe',
                'municipality' => 'Getafe',
                'community' => 'Comunidad de Madrid',
            ])
            ->assertSessionHas('ok');

        $this->actingAs($jefa)
            ->post(route('equipo.centro', $ana), ['centro' => 'Sede Getafe'])
            ->assertSessionHas('ok');

        $this->assertSame('Getafe', $ana->fresh()->sede->municipality);
        $this->assertDatabaseHas('users', [
            'id' => $ana->id,
            'centro_id' => Centro::query()->where('name', 'Sede Getafe')->value('id'),
        ]);
    }

    public function test_cambiar_el_municipio_de_una_sede_arrastra_a_las_personas_y_sus_festivos(): void
    {
        $jefa = $this->jefa();
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test');
        $sede = Centro::create([
            'name' => 'Sede Sur',
            'municipality' => 'Getafe',
            'community' => 'Madrid',
        ]);
        $ana->update([
            'centro_id' => $sede->id,
            'municipality' => 'Getafe',
        ]);

        Festivo::create([
            'holiday_date' => '2027-05-15',
            'name' => 'Fiestas de Getafe',
            'ambito' => 'pueblo',
            'municipality' => 'Getafe',
            'centro_id' => $sede->id,
        ]);
        Festivo::create([
            'holiday_date' => '2027-09-24',
            'name' => 'La Mercè',
            'ambito' => 'pueblo',
            'municipality' => 'Barcelona',
            'community' => 'Cataluña',
        ]);

        $this->actingAs($ana)
            ->get(route('calendario', ['anio' => 2027, 'mes' => 5]))
            ->assertSee('Fiestas de Getafe', false)
            ->assertDontSee('La Mercè', false);

        $this->actingAs($jefa)
            ->patch(route('equipo.centros.update', $sede), [
                'name' => 'Sede Sur',
                'municipality' => 'Barcelona',
                'community' => 'Cataluña',
            ])
            ->assertSessionHas('ok');

        $ana->refresh();
        $sede->refresh();
        $this->assertSame('Barcelona', $ana->municipality);
        $this->assertSame('Barcelona', $sede->municipality);

        $this->actingAs($ana)
            ->get(route('calendario', ['anio' => 2027, 'mes' => 9]))
            ->assertSee('La Mercè', false);

        $this->actingAs($ana)
            ->get(route('calendario', ['anio' => 2027, 'mes' => 5]))
            ->assertDontSee('Fiestas de Getafe', false);
    }

    public function test_renombrar_una_sede_conserva_su_calendario_confirmado(): void
    {
        $jefa = $this->jefa();
        $sede = $jefa->sede;
        $this->calendarioConfirmado($sede, 2026);
        $importacion = ImportacionCalendario::query()->where('clave_activa', $sede->id.':2026')->sole();
        Http::preventStrayRequests();

        $this->actingAs($jefa)
            ->patch(route('equipo.centros.update', $sede), [
                'name' => 'Oficina central',
                'municipality' => $sede->municipality,
                'community' => $sede->community,
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('ok');

        $this->assertSame('Oficina central', $sede->fresh()->name);
        $this->assertSame($sede->id.':2026', $importacion->fresh()->clave_activa);
        $this->assertSame(1, ImportacionCalendario::query()->where('centro_id', $sede->id)->count());
        Http::assertNothingSent();
    }

    public function test_cambiar_solo_municipio_o_comunidad_invalida_el_calendario_de_la_sede(): void
    {
        $jefa = $this->jefa();
        Http::preventStrayRequests();

        foreach (['municipality' => 'Getafe', 'community' => 'Castilla-La Mancha'] as $campo => $valor) {
            $sede = Centro::create([
                'name' => 'Sede calendario '.$campo,
                'municipality' => 'Madrid',
                'community' => 'Comunidad de Madrid',
            ]);
            $this->calendarioConfirmado($sede, 2026);
            $importacion = ImportacionCalendario::query()->where('clave_activa', $sede->id.':2026')->sole();
            $datos = $sede->only(['name', 'municipality', 'community']);
            $datos[$campo] = $valor;

            $this->actingAs($jefa)
                ->patch(route('equipo.centros.update', $sede), $datos)
                ->assertSessionHasNoErrors()
                ->assertSessionHas('ok');

            $this->assertSame($valor, $sede->fresh()->getAttribute($campo));
            $this->assertNull($importacion->fresh()->clave_activa);
            $ultima = ImportacionCalendario::query()->where('centro_id', $sede->id)->latest('id')->firstOrFail();
            $this->assertSame('configuracion_modificada', $ultima->estado);
            $this->assertNull($ultima->clave_activa);
            $this->assertSame(2, ImportacionCalendario::query()->where('centro_id', $sede->id)->count());
        }

        Http::assertNothingSent();
    }

    public function test_un_trabajador_no_puede_gestionar_sedes(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test');

        $this->actingAs($ana)
            ->post(route('equipo.centros.store'), [
                'name' => 'Sede Getafe',
                'municipality' => 'Getafe',
                'community' => 'Comunidad de Madrid',
            ])
            ->assertRedirect();
    }

    public function test_la_jefa_descarga_la_plantilla_de_festivos_de_madrid_y_valencia_de_2027(): void
    {
        $plantilla = $this->actingAs($this->jefa())
            ->get(route('calendario.festivos.plantilla'))
            ->assertOk()
            ->assertDownload('plantilla-festivos.csv')
            ->streamedContent();

        $this->assertStringContainsString('fecha,nombre,ambito,municipio,autonomia', $plantilla);
        $this->assertStringNotContainsString('nombre_sede', $plantilla);
        $this->assertStringContainsString('San Isidro Labrador', $plantilla);
        $this->assertStringContainsString('Nuestra Señora de la Almudena', $plantilla);
        $this->assertStringContainsString('San Vicente Mártir', $plantilla);
        $this->assertStringContainsString('Día de la Comunitat Valenciana', $plantilla);
    }

    public function test_la_jefa_previsualiza_e_importa_festivos_por_centro(): void
    {
        $jefa = $this->jefa();
        $centro = Centro::create([
            'name' => 'Sede Getafe',
            'municipality' => 'Getafe',
            'community' => 'Comunidad de Madrid',
        ]);

        $archivo = UploadedFile::fake()->createWithContent(
            'festivos.csv',
            "fecha,nombre,ambito,municipio,autonomia\n2027-05-15,Fiestas de Getafe,local,Getafe,Comunidad de Madrid\n2027-03-19,San José,autonomico,,Comunidad de Madrid\n2027-03-19,San José,autonomico,,Valencia\n2027-01-01,Año Nuevo,nacional,,\n",
        );

        $this->actingAs($jefa)
            ->post(route('calendario.festivos.previsualizar'), ['archivo' => $archivo])
            ->assertSessionHas('festivos_preview');

        $this->assertDatabaseMissing('festivos', [
            'name' => 'Fiestas de Getafe',
        ]);

        $this->actingAs($jefa)
            ->get(route('gestion.index'))
            ->assertSee('Vista previa', false)
            ->assertSee('4 festivos listos para guardar en el calendario.', false)
            ->assertSee('Guardar 4 festivos', false)
            ->assertSee('Enero 2027', false)
            ->assertSee('Año Nuevo')
            ->assertSee('Cancelar');

        $this->actingAs($jefa)
            ->post(route('calendario.festivos.importar'))
            ->assertSessionHas('ok');

        $this->assertDatabaseHas('festivos', [
            'name' => 'Fiestas de Getafe',
            'centro_id' => $centro->id,
            'ambito' => 'pueblo',
            'municipality' => 'Getafe',
        ]);
        $this->assertDatabaseHas('festivos', [
            'name' => 'Año Nuevo',
            'ambito' => 'estado',
        ]);
        $this->assertSame(2, Festivo::query()->where('name', 'San José')->count());
        $this->assertDatabaseHas('festivos', [
            'name' => 'San José',
            'ambito' => 'comunidad',
            'community' => 'Comunidad de Madrid',
        ]);
        $this->assertDatabaseHas('festivos', [
            'name' => 'San José',
            'ambito' => 'comunidad',
            'community' => 'Valencia',
        ]);

        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test');
        $ana->update([
            'centro_id' => $centro->id,
            'municipality' => 'Getafe',
        ]);

        $this->actingAs($ana)
            ->get(route('calendario', ['anio' => 2027, 'mes' => 3]))
            ->assertSee('San José', false);
        $this->actingAs($ana)
            ->get(route('calendario', ['anio' => 2027, 'mes' => 5]))
            ->assertSee('Fiestas de Getafe', false);

        $archivoRepetido = UploadedFile::fake()->createWithContent(
            'festivos-repetidos.csv',
            "fecha,nombre,ambito,municipio,autonomia\n2027-05-15,Fiestas de Getafe,local,Getafe,Comunidad de Madrid\n2027-03-19,San José,autonomico,,Comunidad de Madrid\n2027-03-19,San José,autonomico,,Valencia\n2027-01-01,Año Nuevo,nacional,,\n",
        );

        $this->actingAs($jefa)
            ->post(route('calendario.festivos.previsualizar'), ['archivo' => $archivoRepetido])
            ->assertSessionHas('festivos_preview');

        $this->actingAs($jefa)
            ->post(route('calendario.festivos.importar'))
            ->assertSessionHas('ok');

        $this->assertSame(1, Festivo::query()->whereDate('holiday_date', '2027-05-15')->count());
        $this->assertSame(1, Festivo::query()->whereDate('holiday_date', '2027-01-01')->count());
        $this->assertSame(2, Festivo::query()->whereDate('holiday_date', '2027-03-19')->count());
    }

    public function test_la_jefa_puede_eliminar_un_festivo(): void
    {
        $jefa = $this->jefa();
        $festivo = Festivo::create([
            'holiday_date' => '2027-01-01',
            'name' => 'Prueba',
            'ambito' => 'estado',
            'community' => null,
            'centro_id' => null,
            'municipality' => '',
        ]);

        $this->actingAs($jefa)
            ->delete(route('calendario.festivos.destroy', $festivo))
            ->assertSessionHas('ok');

        $this->assertDatabaseMissing('festivos', ['id' => $festivo->id]);
    }

    public function test_la_jefa_puede_editar_un_festivo(): void
    {
        $jefa = $this->jefa();
        $festivo = Festivo::create([
            'holiday_date' => '2027-01-01',
            'name' => 'Nombre antiguo',
            'ambito' => 'estado',
            'community' => null,
            'centro_id' => null,
            'municipality' => '',
        ]);

        $this->actingAs($jefa)
            ->patch(route('calendario.festivos.update', $festivo), [
                'holiday_date' => '2027-01-06',
                'name' => 'Reyes Magos',
                'ambito' => 'estado',
                'community' => '',
                'centro' => '',
            ])
            ->assertSessionHas('ok');

        $this->assertDatabaseHas('festivos', [
            'id' => $festivo->id,
            'holiday_date' => '2027-01-06 00:00:00',
            'name' => 'Reyes Magos',
        ]);
    }

    private function jefa(): User
    {
        return User::factory()->jefa()->create([
            'name' => 'Lola Pérez',
            'email' => 'lola.perez@abaco.test',
        ]);
    }

    private function persona(string $nombre, string $correo, string $papel = 'trabajadora'): User
    {
        return User::factory()->create([
            'name' => $nombre,
            'email' => $correo,
            'role' => $papel,
        ]);
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

    private function jornadaCerrada(User $user, string $dia, string $inicio, string $fin): void
    {
        $jornada = Jornada::create(['user_id' => $user->id, 'work_date' => $dia]);
        Tramo::create([
            'jornada_id' => $jornada->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => $dia.' '.$inicio.':00',
            'ended_at' => $dia.' '.$fin.':00',
        ]);
    }
}
