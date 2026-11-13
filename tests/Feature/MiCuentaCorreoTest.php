<?php

namespace Tests\Feature;

use App\Http\Middleware\ProtectFormRequests;
use App\Mail\ConfirmarCambioCorreo;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MiCuentaCorreoTest extends TestCase
{
    use RefreshDatabase;

    private User $persona;

    protected function setUp(): void
    {
        parent::setUp();
        $this->persona = User::factory()->create(['email' => 'provisional@cuentas.invalid', 'password' => 'ClaveSegura2026']);
        $this->persona->forceFill(['correo_habilitado' => false, 'remember_token' => 'recuerdo-anterior'])->save();
        Mail::fake();
    }

    public function test_solicita_a_destino_nuevo_sin_cambiar_acceso_ni_habilitar_correo(): void
    {
        $this->solicitar();
        $this->assertSame('provisional@cuentas.invalid', $this->persona->fresh()->email);
        $this->assertFalse($this->persona->fresh()->correo_habilitado);
        $this->assertDatabaseHas('cambios_correo_pendientes', ['user_id' => $this->persona->id, 'correo_nuevo' => 'real@example.test']);
        Mail::assertSent(ConfirmarCambioCorreo::class, fn (ConfirmarCambioCorreo $m) => $m->hasTo('real@example.test')
            && str_contains($m->render(), 'Primero inicia sesión'));
        $enlace = Mail::sent(ConfirmarCambioCorreo::class)->first()->enlace;
        $token = parse_url($enlace, PHP_URL_FRAGMENT);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $token);
        $this->assertStringNotContainsString($token, parse_url($enlace, PHP_URL_PATH));
        $this->assertDatabaseHas('cambios_correo_pendientes', ['user_id' => $this->persona->id, 'token_hash' => hash('sha256', $token)]);
        $this->assertDatabaseMissing('cambios_correo_pendientes', ['user_id' => $this->persona->id, 'token_hash' => $token]);
    }

    public function test_confirmacion_cambia_correo_activa_envios_y_revoca_enlaces_previos(): void
    {
        $this->solicitar();
        $token = $this->token();
        DB::table('password_reset_tokens')->insert([
            ['email' => $this->persona->email, 'token' => 'hash-antiguo', 'created_at' => now()],
            ['email' => 'real@example.test', 'token' => 'hash-destino', 'created_at' => now()],
        ]);
        $this->actingAs($this->persona)->get(route('mi-cuenta.correo.confirmar'))->assertOk()->assertSee('Confirmar cambio');
        $this->assertFalse($this->persona->fresh()->correo_habilitado);
        $this->actingAs($this->persona)->post(route('mi-cuenta.correo.aplicar'), ['token' => $token])->assertSessionHas('ok');
        $actual = $this->persona->fresh();
        $this->assertSame('real@example.test', $actual->email);
        $this->assertTrue($actual->correo_habilitado);
        $this->assertNotNull($actual->email_verified_at);
        $this->assertNotSame('recuerdo-anterior', $actual->getRememberToken());
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertDatabaseCount('cambios_correo_pendientes', 0);
        $this->assertDatabaseHas('eventos_cambio_correo', ['user_id' => $this->persona->id,
            'correo_anterior' => 'provisional@cuentas.invalid', 'correo_nuevo' => 'real@example.test']);
        $this->actingAs($actual)->post(route('mi-cuenta.correo.aplicar'), ['token' => $token])->assertSessionHas('aviso');
        $this->assertDatabaseCount('eventos_cambio_correo', 1);
    }

    public function test_no_se_puede_abrir_pagina_de_confirmacion_sin_sesion(): void
    {
        $this->get(route('mi-cuenta.correo.confirmar'))->assertRedirect(route('login'));
    }

    public function test_sqlite_impide_correos_duplicados_sin_distinguir_mayusculas(): void
    {
        $this->expectException(UniqueConstraintViolationException::class);
        User::factory()->create(['email' => 'PROVISIONAL@CUENTAS.INVALID']);
    }

    public function test_no_confirma_si_el_correo_original_cambio_tras_la_solicitud(): void
    {
        $this->solicitar();
        $token = $this->token();
        $this->persona->forceFill(['email' => 'otro@cuentas.invalid'])->save();
        $this->actingAs($this->persona)->post(route('mi-cuenta.correo.aplicar'), ['token' => $token])->assertSessionHas('aviso');
        $this->assertSame('otro@cuentas.invalid', $this->persona->fresh()->email);
        $this->assertFalse($this->persona->fresh()->correo_habilitado);
        $this->assertDatabaseCount('eventos_cambio_correo', 0);
    }

    public function test_confirmar_revoca_sesiones_anteriores_y_conserva_sesion_actual(): void
    {
        config(['session.driver' => 'database']);
        app('session')->forgetDrivers();
        $this->solicitar();
        $token = $this->token();
        $idAnterior = session()->getId();
        DB::table('sessions')->insert([
            'id' => 'otra-sesion', 'user_id' => $this->persona->id,
            'payload' => '', 'last_activity' => time(),
        ]);
        $this->actingAs($this->persona)->post(route('mi-cuenta.correo.aplicar'), ['token' => $token])->assertSessionHas('ok');
        $idActual = session()->getId();
        $this->assertNotSame($idAnterior, $idActual);
        $this->assertDatabaseMissing('sessions', ['id' => $idAnterior]);
        $this->assertDatabaseMissing('sessions', ['id' => 'otra-sesion']);
        $this->assertDatabaseHas('sessions', ['id' => $idActual, 'user_id' => $this->persona->id]);
        $this->get(route('mi-cuenta'))->assertOk();
    }

    public function test_un_usuario_distinto_no_puede_confirmar_el_enlace_ajeno(): void
    {
        $this->solicitar();
        $token = $this->token();
        $otro = User::factory()->create();
        $this->actingAs($otro)->post(route('mi-cuenta.correo.aplicar'), ['token' => $token])->assertSessionHas('aviso');
        $this->assertSame('provisional@cuentas.invalid', $this->persona->fresh()->email);
        $this->assertFalse($this->persona->fresh()->correo_habilitado);
        $this->assertDatabaseCount('eventos_cambio_correo', 0);
    }

    public function test_clave_incorrecta_y_correo_duplicado_o_falso_no_envian(): void
    {
        $this->actingAs($this->persona)->post(route('mi-cuenta.correo.solicitar'), ['email' => 'nuevo@example.test', 'current_password' => 'incorrecta'])
            ->assertSessionHasErrors('current_password');
        User::factory()->create(['email' => 'ocupado@example.test']);
        $this->actingAs($this->persona)->post(route('mi-cuenta.correo.solicitar'), ['email' => 'OCUPADO@example.test', 'current_password' => 'ClaveSegura2026'])
            ->assertSessionHasErrors('email');
        $this->actingAs($this->persona)->post(route('mi-cuenta.correo.solicitar'), ['email' => 'otra@cuentas.invalid', 'current_password' => 'ClaveSegura2026'])
            ->assertSessionHasErrors('email');
        Mail::assertNothingSent();
        $this->assertDatabaseCount('cambios_correo_pendientes', 0);
    }

    public function test_un_enlace_anterior_y_uno_caducado_no_modifican_la_cuenta(): void
    {
        $this->solicitar();
        $anterior = $this->token();
        $this->solicitar('otro@example.test');
        $nuevo = parse_url(Mail::sent(ConfirmarCambioCorreo::class)->last()->enlace, PHP_URL_FRAGMENT);
        $this->assertNotSame($anterior, $nuevo);
        $this->actingAs($this->persona)->post(route('mi-cuenta.correo.aplicar'), ['token' => $anterior])->assertSessionHas('aviso');
        $this->travel(61)->minutes();
        $this->actingAs($this->persona)->post(route('mi-cuenta.correo.aplicar'), ['token' => $nuevo])->assertSessionHas('aviso');
        $this->assertSame('provisional@cuentas.invalid', $this->persona->fresh()->email);
        $this->assertDatabaseCount('eventos_cambio_correo', 0);
    }

    public function test_el_enlace_se_invalida_si_cambia_la_contrasena_o_el_correo_original(): void
    {
        $this->solicitar();
        $token = $this->token();
        $this->persona->forceFill(['password' => 'OtraClaveSegura2026'])->save();
        $this->actingAs($this->persona->fresh())->post(route('mi-cuenta.correo.aplicar'), ['token' => $token])->assertSessionHas('aviso');
        $this->assertDatabaseCount('eventos_cambio_correo', 0);
    }

    public function test_colision_producida_despues_de_solicitar_no_cambia_ninguna_cuenta(): void
    {
        $this->solicitar();
        $token = $this->token();
        User::factory()->create(['email' => 'real@example.test']);
        $this->actingAs($this->persona)->post(route('mi-cuenta.correo.aplicar'), ['token' => $token])->assertSessionHas('aviso');
        $this->assertSame('provisional@cuentas.invalid', $this->persona->fresh()->email);
        $this->assertFalse($this->persona->fresh()->correo_habilitado);
        $this->assertDatabaseCount('eventos_cambio_correo', 0);
    }

    public function test_en_produccion_sin_correo_real_no_se_crea_peticion_ni_se_envia(): void
    {
        $this->app['env'] = 'production';
        config(['mail.default' => 'log']);
        $this->withoutMiddleware(ProtectFormRequests::class);
        $this->actingAs($this->persona)->post(route('mi-cuenta.correo.solicitar'), ['email' => 'real@example.test', 'current_password' => 'ClaveSegura2026'])
            ->assertSessionHas('aviso', 'La confirmación por correo no está disponible temporalmente.');
        $this->assertDatabaseCount('cambios_correo_pendientes', 0);
        Mail::assertNothingSent();
    }

    public function test_si_falla_el_envio_no_queda_un_enlace_activo(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Fallo SMTP simulado'));
        $this->actingAs($this->persona)->post(route('mi-cuenta.correo.solicitar'), ['email' => 'real@example.test', 'current_password' => 'ClaveSegura2026'])
            ->assertSessionHas('aviso', 'No se pudo enviar la confirmación. Inténtalo más tarde.');
        $this->assertDatabaseCount('cambios_correo_pendientes', 0);
        $this->assertSame('provisional@cuentas.invalid', $this->persona->fresh()->email);
    }

    private function solicitar(string $correo = 'real@example.test'): void
    {
        $this->actingAs($this->persona)->post(route('mi-cuenta.correo.solicitar'), ['email' => $correo, 'current_password' => 'ClaveSegura2026'])
            ->assertRedirect(route('mi-cuenta'))->assertSessionHas('ok');
    }

    private function token(): string
    {
        return parse_url(Mail::sent(ConfirmarCambioCorreo::class)->first()->enlace, PHP_URL_FRAGMENT);
    }
}
