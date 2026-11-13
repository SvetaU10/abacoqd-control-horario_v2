<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AccesoTemporalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.access_until' => '2026-11-07T00:00:00+01:00']);
    }

    public function test_el_viernes_completo_admite_inicio_de_sesion_y_uso(): void
    {
        $this->travelTo(Carbon::parse('2026-11-06T23:59:59.999999+01:00'));
        $persona = User::factory()->create(['password' => 'ClaveTemporalSegura']);

        $this->post(route('login'), ['email' => $persona->email, 'password' => 'ClaveTemporalSegura'])
            ->assertRedirect(route('jornada'));
        $this->assertAuthenticatedAs($persona);
        $this->get(route('tickets.create'))->assertOk();
    }

    public function test_el_sabado_a_medianoche_bloquea_el_login_y_explica_el_cierre(): void
    {
        $this->travelTo(Carbon::parse('2026-11-07T00:00:00+01:00'));
        $persona = User::factory()->create(['password' => 'ClaveTemporalSegura']);

        $this->get(route('login'))->assertForbidden()->assertSee('Pruebas finalizadas');
        $this->post(route('login'), ['email' => $persona->email, 'password' => 'ClaveTemporalSegura'])
            ->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertGuest();
        $this->assertTrue($persona->fresh()->active);
    }

    public function test_una_sesion_abierta_se_cierra_y_no_ejecuta_el_fichaje(): void
    {
        $this->travelTo(Carbon::parse('2026-11-06T23:59:59+01:00'));
        $persona = User::factory()->create();
        $this->actingAs($persona)->withSession(['dato_privado' => 'sesion anterior']);
        $this->get(route('tickets.create'))->assertOk();

        $this->travelTo(Carbon::parse('2026-11-07T00:00:00+01:00'));
        $this->post(route('jornada.entrada'))->assertForbidden()->assertSessionMissing('dato_privado');

        $this->assertGuest();
        $this->assertDatabaseCount('jornadas', 0);
        $this->assertDatabaseCount('tramos', 0);
    }

    public function test_el_limite_equivale_a_las_23_utc_aunque_la_zona_actual_sea_otra(): void
    {
        $this->travelTo(Carbon::parse('2026-11-06T22:59:59+00:00'));
        $this->get(route('login'))->assertOk();

        $this->travelTo(Carbon::parse('2026-11-06T23:00:00+00:00'));
        $this->get(route('login'))->assertForbidden();
    }

    public function test_no_se_puede_recuperar_la_clave_ni_usar_un_enlace_previo_despues_del_cierre(): void
    {
        Mail::fake();
        $persona = User::factory()->create(['password' => 'ClaveAnteriorSegura']);
        $token = Password::broker()->createToken($persona);
        $this->travelTo(Carbon::parse('2026-11-07T00:00:00+01:00'));

        $this->get(route('password.request'))->assertForbidden();
        $this->post(route('password.email'), ['email' => $persona->email])->assertForbidden();
        $this->get(route('password.reset', ['token' => $token, 'email' => $persona->email]))->assertForbidden();
        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $persona->email,
            'password' => 'OtraClaveTemporalSegura',
            'password_confirmation' => 'OtraClaveTemporalSegura',
        ])->assertForbidden();

        Mail::assertNothingSent();
        $this->assertTrue(Hash::check('ClaveAnteriorSegura', $persona->fresh()->password));
        $this->assertGuest();
    }

    public function test_restaurar_cuentas_activas_o_crear_otras_no_salta_la_fecha_de_la_instalacion(): void
    {
        $this->travelTo(Carbon::parse('2026-11-07T00:00:00+01:00'));
        $restaurada = User::factory()->create(['active' => false, 'role' => 'tecnico']);
        DB::table('users')->where('id', $restaurada->id)->update(['active' => true]);
        $nueva = User::factory()->jefa()->create();

        foreach ([$restaurada->fresh(), $nueva] as $persona) {
            $this->actingAs($persona)->get(route('tecnico.index'))->assertForbidden();
            $this->assertGuest();
        }

        $this->assertDatabaseCount('users', 2);
        $this->assertSame('2026-11-07T00:00:00+01:00', config('app.access_until'));
    }

    public function test_sin_limite_o_con_valor_vacio_el_funcionamiento_habitual_se_conserva(): void
    {
        $this->travelTo(Carbon::parse('2027-01-01T00:00:00+01:00'));

        foreach ([null, '', '   '] as $limite) {
            config(['app.access_until' => $limite]);
            $this->get(route('login'))->assertOk();
        }

        $persona = User::factory()->create();
        $this->actingAs($persona)->get(route('tickets.create'))->assertOk();
        $this->assertAuthenticatedAs($persona);
    }

    public function test_una_fecha_no_valida_cierra_el_acceso_en_lugar_de_dejarlo_indefinido(): void
    {
        $this->travelTo(Carbon::parse('2026-11-03T12:00:00+01:00'));

        foreach (['no-es-una-fecha', '2026-11-31T00:00:00+01:00', '2026-11-07'] as $limite) {
            config(['app.access_until' => $limite]);
            $this->get(route('login'))->assertForbidden()->assertSee('Pruebas finalizadas');
        }
    }

    public function test_la_comprobacion_de_salud_sigue_disponible_despues_del_cierre(): void
    {
        $this->travelTo(Carbon::parse('2026-11-07T00:00:00+01:00'));

        $this->get('/up')->assertOk();
    }
}
