<?php

namespace Tests\Feature;

use App\Exceptions\ReglaJornada;
use App\Models\Centro;
use App\Models\Horario;
use App\Models\Incidencia;
use App\Models\Jornada;
use App\Models\Ticket;
use App\Models\Tramo;
use App\Models\User;
use App\Services\CupoVacaciones;
use App\Services\JornadaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class TecnicoSinJornadaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-06 10:00:00', 'Europe/Madrid'));
        Mail::fake();
    }

    public function test_cuatro_accesos_cuentan_tres_personas_y_it_entra_en_sistema(): void
    {
        $jefe = User::factory()->jefa()->create();
        User::factory()->count(2)->create();
        $it = User::factory()->soloTecnico()->create(['name' => 'Soporte exclusivamente técnico']);

        $this->assertDatabaseCount('users', 4);
        $this->assertSame(3, User::query()->conJornada()->count());
        $this->actingAs($jefe)->get(route('equipo.index'))
            ->assertOk()->assertViewHas('totalPersonas', 3)->assertDontSee($it->name);
        $this->actingAs($jefe)->get(route('jefe.index'))
            ->assertOk()->assertViewHas('personas', fn ($personas) => $personas->count() === 3)->assertDontSee($it->name);
        $this->actingAs($jefe)->get(route('incidencias.index'))->assertOk()->assertDontSee($it->name);

        $this->post(route('logout'));
        $this->post(route('login'), ['email' => $it->email, 'password' => 'password'])
            ->assertRedirect(route('tecnico.index'));
        $this->get(route('jornada'))->assertRedirect(route('tecnico.index'));
        $this->get(route('login'))->assertRedirect(route('tecnico.index'));
        $this->get(route('password.request'))->assertRedirect(route('tecnico.index'));
        $this->get(route('tecnico.index'))->assertOk()
            ->assertSee('Sistema')->assertSee('Tickets')->assertDontSee('Mi jornada')->assertDontSee('Mi registro');
    }

    public function test_it_no_puede_escribir_ni_consultar_registro_laboral_por_url_directa(): void
    {
        $empleado = User::factory()->create();
        $jornada = Jornada::create(['user_id' => $empleado->id, 'work_date' => '2026-11-06']);
        $tramo = Tramo::create(['jornada_id' => $jornada->id, 'tipo' => 'trabajo', 'started_at' => '2026-11-06 09:00:00', 'ended_at' => '2026-11-06 09:30:00']);
        $incidencia = Incidencia::create(['user_id' => $empleado->id, 'cuerpo' => 'Ejemplo de incidencia laboral', 'estado' => Incidencia::PENDIENTE]);
        $this->actingAs(User::factory()->soloTecnico()->create());

        foreach (['jornada.entrada', 'jornada.salida', 'jornada.pausa', 'jornada.volver', 'jornada.ya', 'jornada.reunion', 'jornada.cerrar', 'jornada.seguir', 'jornada.incidencia', 'jornada.festivo', 'jornada.completar', 'registro.explicar', 'calendario.vacaciones'] as $ruta) {
            $this->post(route($ruta))->assertForbidden();
        }
        $this->post(route('registro.dia.horas', '2026-11-06'))->assertForbidden();
        $this->post(route('tramos.corregir', $tramo))->assertForbidden();
        $this->post(route('incidencias.mensaje', $incidencia))->assertForbidden();
        foreach (['registro', 'registro.csv', 'calendario', 'jornada.incidencia.crear'] as $ruta) {
            $this->get(route($ruta))->assertForbidden();
        }
        $this->get(route('registro.show', $jornada))->assertForbidden();
        $this->assertDatabaseCount('jornadas', 1);
        $this->assertDatabaseCount('tramos', 1);
        $this->assertDatabaseCount('solicitudes_vacaciones', 0);
        $this->assertDatabaseCount('incidencias', 1);
    }

    public function test_el_jefe_no_asigna_horario_ni_incidencias_a_una_cuenta_it(): void
    {
        $it = User::factory()->soloTecnico()->create();
        $this->actingAs(User::factory()->jefa()->create());

        $this->get(route('equipo.show', $it))->assertNotFound();
        $this->get(route('jefe.persona', $it))->assertNotFound();
        $this->post(route('equipo.horario', $it))->assertNotFound();
        $this->post(route('equipo.ausencia', $it))->assertNotFound();
        $this->post(route('jornada.incidencia'), ['destinatario' => $it->id, 'motivo' => 'Necesito que revises este registro laboral.'])
            ->assertSessionHasErrors('destinatario');
        $this->assertDatabaseCount('horarios', 0);
        $this->assertDatabaseCount('ausencias', 0);
        $this->assertDatabaseCount('incidencias', 0);
    }

    public function test_it_conserva_soporte_y_mantenimiento_sin_permiso_de_jefe(): void
    {
        $empleado = User::factory()->create();
        $it = User::factory()->soloTecnico()->create();
        $this->actingAs($empleado)->post(route('tickets.store'), ['asunto' => 'Error de acceso', 'descripcion' => 'La pantalla muestra un error al entrar.'])->assertRedirect();
        $ticket = Ticket::query()->sole();

        $this->actingAs($it)->get(route('tecnico.tickets'))->assertOk()->assertSee('Error de acceso');
        $this->get(route('tickets.show', $ticket))->assertOk();
        $this->post(route('tickets.mensaje', $ticket), ['cuerpo' => 'Estamos revisando el problema.'])->assertRedirect();
        $this->post(route('tecnico.actualizar'), ['confirmo' => '1'])->assertSessionHas('ok');
        $this->get(route('equipo.index'))->assertRedirect(route('tecnico.index'));
        $this->assertDatabaseCount('ticket_mensajes', 1);
        $this->assertDatabaseCount('acciones_tecnicas', 1);
    }

    public function test_it_no_genera_avisos_ni_cupo_aunque_reciba_por_error_un_horario(): void
    {
        $centro = Centro::query()->where('name', 'Madrid')->firstOrFail();
        $it = User::factory()->soloTecnico()->create(['starts_on' => '2026-01-01', 'centro_id' => $centro->id]);
        Horario::create(['user_id' => $it->id, 'morning_start' => '09:00', 'morning_end' => '14:00', 'afternoon_start' => '15:00', 'afternoon_end' => '18:00', 'effective_from' => '2026-01-01']);
        $this->calendarioConfirmado($centro, 2026);

        $this->artisan('jornada:avisar-inicio')->assertSuccessful();
        $this->assertNull(app(JornadaService::class)->avisarDeInicio($it));
        $this->assertSame('cuenta_tecnica', app(JornadaService::class)->expectativaDe($it, now())['motivo']);
        $this->assertSame(0, app(CupoVacaciones::class)->restantes($it, 2026));
        $this->assertDatabaseCount('avisos', 0);
        Mail::assertNothingSent();

        $this->expectException(ReglaJornada::class);
        app(JornadaService::class)->entrar($it);
    }

    public function test_it_recupera_la_clave_y_vuelve_a_sistema(): void
    {
        $it = User::factory()->soloTecnico()->create();
        $token = Password::broker()->createToken($it);

        $this->post(route('password.update'), [
            'email' => $it->email,
            'token' => $token,
            'password' => 'ClaveNuevaSoloParaPrueba2026',
            'password_confirmation' => 'ClaveNuevaSoloParaPrueba2026',
        ])->assertRedirect(route('tecnico.index'));

        $this->assertAuthenticatedAs($it);
        $this->assertFalse($it->fresh()->registraJornada());
        $this->get(route('tecnico.index'))->assertOk();
    }

    public function test_el_alta_limpia_crea_cuatro_accesos_sin_horarios_fechas_ni_fichajes(): void
    {
        $centro = Centro::query()->where('name', 'Madrid')->firstOrFail();
        foreach (['jefe', 'trabajador', 'trabajador', 'tecnico'] as $indice => $rol) {
            $opciones = $rol === 'tecnico' ? ['--solo-tecnico' => true] : ['--centro' => $centro->id];
            $this->artisan('abaco:crear-administrador', ['email' => 'cuenta'.$indice.'@abaco.test', 'nombre' => 'Cuenta '.$indice, '--rol' => $rol] + $opciones)
                ->expectsQuestion('Contraseña (mínimo 12 caracteres)', 'ClaveSoloParaEstaPrueba2026')
                ->expectsQuestion('Repite la contraseña', 'ClaveSoloParaEstaPrueba2026')
                ->assertSuccessful();
        }

        $this->assertDatabaseCount('users', 4);
        $this->assertSame(3, User::query()->conJornada()->count());
        $this->assertSame(0, User::query()->whereNotNull('starts_on')->count());
        $this->assertDatabaseCount('horarios', 0);
        $this->assertDatabaseCount('jornadas', 0);
        $this->assertNull(User::query()->where('role', 'tecnico')->sole()->centro_id);
        $this->artisan('jornada:avisar-inicio')->assertSuccessful();
        $this->assertDatabaseCount('avisos', 0);
        Mail::assertNothingSent();
    }

    public function test_el_alta_respeta_fechas_explicitas_y_rechaza_combinaciones_incoherentes(): void
    {
        $base = ['email' => 'alta@abaco.test', 'nombre' => 'Alta explícita', '--rol' => 'trabajador', '--centro' => Centro::query()->where('name', 'Madrid')->value('id')];
        foreach ([['--hasta' => '2026-12-15'], ['--desde' => '2026-12-15', '--hasta' => '2026-12-01'], ['--solo-tecnico' => true], ['--desde' => '06/11/2026']] as $opciones) {
            $this->artisan('abaco:crear-administrador', $base + $opciones)->assertFailed();
        }
        $this->assertDatabaseCount('users', 0);
        $this->artisan('abaco:crear-administrador', $base + ['--desde' => '2026-11-09', '--hasta' => '2027-06-30'])
            ->expectsQuestion('Contraseña (mínimo 12 caracteres)', 'ClaveSoloParaEstaPrueba2026')
            ->expectsQuestion('Repite la contraseña', 'ClaveSoloParaEstaPrueba2026')
            ->assertSuccessful();
        $this->assertDatabaseHas('users', ['email' => 'alta@abaco.test', 'starts_on' => '2026-11-09 00:00:00', 'ends_on' => '2027-06-30 00:00:00']);
    }

    public function test_los_roles_antiguos_conservan_permisos_y_se_normalizan_sin_tocar_historico(): void
    {
        $jefe = User::factory()->jefa()->create();
        $empleado = User::factory()->create();
        $tecnico = User::factory()->tecnico()->create();
        $jornada = Jornada::create(['user_id' => $empleado->id, 'work_date' => '2026-10-30']);
        $password = $empleado->password;
        $migracion = require database_path('migrations/2026_11_06_100000_normalizar_roles_y_separar_cuentas_tecnicas.php');
        $migracion->down();

        $this->assertSame('jefa', DB::table('users')->where('id', $jefe->id)->value('role'));
        $this->assertSame('trabajadora', DB::table('users')->where('id', $empleado->id)->value('role'));
        $this->assertTrue($jefe->fresh()->esJefa());
        $this->assertTrue(User::query()->jefes()->whereKey($jefe->id)->exists());

        $migracion->up();
        $this->assertSame('jefe', $jefe->fresh()->role);
        $this->assertSame('trabajador', $empleado->fresh()->role);
        $this->assertSame('tecnico', $tecnico->fresh()->role);
        $this->assertTrue($tecnico->fresh()->registraJornada());
        $this->assertSame($password, $empleado->fresh()->password);
        $this->assertDatabaseHas('jornadas', ['id' => $jornada->id, 'user_id' => $empleado->id]);
        $this->assertSame(3, User::query()->conJornada()->count());
        $this->actingAs($jefe->fresh())->get(route('equipo.index'))->assertOk();
        $this->actingAs($tecnico->fresh())->post(route('jornada.entrada'))->assertRedirect();
        $this->assertTrue(Jornada::query()->where('user_id', $tecnico->id)->whereDate('work_date', '2026-11-06')->exists());
    }
}
