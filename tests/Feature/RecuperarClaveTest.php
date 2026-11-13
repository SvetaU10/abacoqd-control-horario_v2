<?php

namespace Tests\Feature;

use App\Http\Middleware\ProtectFormRequests;
use App\Mail\RecuperarClave;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class RecuperarClaveTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_acceso_ofrece_recuperar_la_contraseña(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('No recuerdo la contraseña', false)
            ->assertSee(route('password.request'), false);

        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('Escribe el correo de tu cuenta para solicitar un enlace de recuperación.')
            ->assertDontSee('Si no, te lo decimos aquí.');
    }

    public function test_pedir_el_enlace_escribe_el_correo_si_el_acceso_existe(): void
    {
        Mail::fake();
        $ana = $this->persona();

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => $ana->email])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('ok', 'Si el correo pertenece a una cuenta activa, recibirás un enlace. Revisa también el correo no deseado.');

        Mail::assertSent(RecuperarClave::class, fn (RecuperarClave $correo) => $correo->hasTo($ana->email)
            && str_contains($correo->render(), 'Elegir una contraseña nueva'));
    }

    public function test_un_correo_desconocido_recibe_la_misma_respuesta_sin_enviar_correo(): void
    {
        Mail::fake();

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => 'nadie@abaco.test'])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('ok', 'Si el correo pertenece a una cuenta activa, recibirás un enlace. Revisa también el correo no deseado.');

        Mail::assertNothingSent();
    }

    public function test_un_acceso_cerrado_no_recibe_el_enlace(): void
    {
        Mail::fake();
        $ana = $this->persona();
        $ana->update(['active' => false]);

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => $ana->email])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('ok', 'Si el correo pertenece a una cuenta activa, recibirás un enlace. Revisa también el correo no deseado.');

        Mail::assertNothingSent();
    }

    public function test_produccion_no_promete_entrega_ni_genera_token_con_correo_simulado(): void
    {
        Mail::fake();
        $ana = $this->persona();
        $this->app['env'] = 'production';
        $this->withoutMiddleware(ProtectFormRequests::class);

        foreach (['log', 'array', 'failover'] as $mailer) {
            config(['mail.default' => $mailer]);
            $this->from(route('password.request'))
                ->post(route('password.email'), ['email' => $ana->email])
                ->assertRedirect(route('password.request'))
                ->assertSessionHas('aviso', 'La recuperación de contraseña no está disponible temporalmente.');
        }

        Mail::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_fallo_de_envio_no_revela_si_la_cuenta_existe_ni_registra_secretos(): void
    {
        $ana = $this->persona();
        Password::shouldReceive('sendResetLink')->once()->andThrow(new \RuntimeException('detalle sensible'));
        Log::shouldReceive('warning')->once()->with('No se pudo enviar un enlace de recuperación.');

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => $ana->email])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('ok', 'Si el correo pertenece a una cuenta activa, recibirás un enlace. Revisa también el correo no deseado.')
            ->assertDontSee('detalle sensible');
    }

    public function test_cuenta_inactiva_con_token_invalido_muestra_el_mismo_aviso(): void
    {
        $ana = $this->persona();
        $ana->update(['active' => false]);

        $this->post(route('password.update'), [
            'token' => 'inventado', 'email' => $ana->email,
            'password' => 'NuevaClave1', 'password_confirmation' => 'NuevaClave1',
        ])->assertSessionHas('aviso', 'Ese enlace ya no vale. Pide otro.');
    }

    public function test_restablecer_revoca_sesiones_de_esa_persona_y_mantiene_las_ajenas(): void
    {
        config(['session.driver' => 'database']);
        app('session')->forgetDrivers();
        $ana = $this->persona();
        $otra = User::factory()->create();
        foreach (['sesion-antigua-1' => $ana->id, 'sesion-antigua-2' => $ana->id, 'sesion-ajena' => $otra->id] as $id => $userId) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => base64_encode(serialize([])), 'last_activity' => time()]);
        }
        $token = Password::broker()->createToken($ana);

        $this->post(route('password.update'), [
            'token' => $token, 'email' => $ana->email,
            'password' => 'NuevaClave1', 'password_confirmation' => 'NuevaClave1',
        ])->assertRedirect(route('jornada'));

        $this->assertDatabaseMissing('sessions', ['id' => 'sesion-antigua-1']);
        $this->assertDatabaseMissing('sessions', ['id' => 'sesion-antigua-2']);
        $this->assertDatabaseHas('sessions', ['id' => 'sesion-ajena', 'user_id' => $otra->id]);
        $this->assertAuthenticatedAs($ana->fresh());
        $this->get(route('jornada'))->assertOk();
    }

    public function test_el_enlace_cambia_la_contraseña_y_entra(): void
    {
        $ana = $this->persona();
        $token = Password::broker()->createToken($ana);

        $this->get(route('password.reset', ['token' => $token, 'email' => $ana->email]))
            ->assertOk()
            ->assertSee('Contraseña nueva', false);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $ana->email,
            'password' => 'NuevaClave1',
            'password_confirmation' => 'NuevaClave1',
        ])->assertRedirect(route('jornada'));

        $this->assertAuthenticatedAs($ana->fresh());
        $this->post(route('logout'));
        $this->post(route('login'), [
            'email' => $ana->email,
            'password' => 'NuevaClave1',
        ])->assertRedirect(route('jornada'));
    }

    public function test_un_enlace_caducado_no_cambia_la_contraseña(): void
    {
        $ana = $this->persona();

        $this->from(route('password.reset', ['token' => 'inventado', 'email' => $ana->email]))
            ->post(route('password.update'), [
                'token' => 'inventado',
                'email' => $ana->email,
                'password' => 'NuevaClave1',
                'password_confirmation' => 'NuevaClave1',
            ])
            ->assertRedirect(route('password.reset', ['token' => 'inventado', 'email' => $ana->email]))
            ->assertSessionHas('aviso', 'Ese enlace ya no vale. Pide otro.');
    }

    private function persona(): User
    {
        return User::create([
            'name' => 'Ana López',
            'email' => 'ana.lopez@abaco.test',
            'password' => 'Jornada2026',
            'role' => 'trabajadora',
            'active' => true,
            'starts_on' => '2026-01-07',
            'municipality' => 'Madrid',
        ]);
    }
}
