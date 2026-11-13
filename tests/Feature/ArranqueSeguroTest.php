<?php

namespace Tests\Feature;

use App\Models\Centro;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class ArranqueSeguroTest extends TestCase
{
    use DatabaseMigrations;

    protected $seed = false;

    protected $seeder = false;

    public function test_la_instalacion_no_crea_sedes_ni_cuentas_de_demostracion_desde_las_migraciones(): void
    {
        $this->assertDatabaseCount('centros', 0);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('municipios', 8132);
    }

    public function test_el_seeder_normal_crea_solo_principal_en_madrid_y_es_repetible(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('centros', 1);
        $this->assertDatabaseHas('centros', ['name' => 'Principal', 'municipality' => 'Madrid', 'municipio_ine' => '28079', 'province_code' => '28', 'comunidad_codigo' => 'ES-MD']);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('horarios', 0);
        $this->assertDatabaseCount('jornadas', 0);
        $this->assertDatabaseCount('festivos', 0);
    }

    public function test_la_demo_no_puede_sembrarse_en_produccion_aunque_se_active_la_bandera(): void
    {
        $this->app->instance('env', 'production');
        config(['app.demo' => true]);
        $this->expectException(RuntimeException::class);

        try {
            app(DemoSeeder::class)->run();
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_el_login_normal_no_publica_cuentas_ficticias(): void
    {
        config(['app.demo' => false]);

        $this->get(route('login'))->assertOk()->assertDontSee('Cuentas de prueba')->assertDontSee('Jornada2026')->assertDontSee('marta.ruiz@abaco.test');
    }

    public function test_la_ayuda_de_demo_solo_aparece_tras_activarla_expresamente(): void
    {
        config(['app.demo' => true]);

        $this->get(route('login'))->assertOk()->assertSee('Cuentas de prueba');
    }

    public function test_el_administrador_se_crea_con_clave_elegida_oculta_sin_actividad_ficticia(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->artisan('abaco:crear-administrador', ['email' => 'administracion@abaco.test', 'nombre' => 'Administración'])
            ->expectsQuestion('Contraseña (mínimo 12 caracteres)', 'ClaveParticularLarga2026')
            ->expectsQuestion('Repite la contraseña', 'ClaveParticularLarga2026')
            ->assertSuccessful();

        $persona = User::query()->sole();
        $this->assertSame('jefe', $persona->role);
        $this->assertSame('Administración', $persona->name);
        $this->assertSame('Principal', $persona->sede->name);
        $this->assertTrue(Hash::check('ClaveParticularLarga2026', $persona->password));
        $this->assertDatabaseCount('horarios', 0);
        $this->assertDatabaseCount('jornadas', 0);
    }

    public function test_el_bootstrap_no_reemplaza_una_cuenta_existente_ni_su_papel(): void
    {
        $this->seed(DatabaseSeeder::class);
        $persona = User::factory()->create(['email' => 'existente@abaco.test', 'centro_id' => Centro::query()->sole()->id]);
        $claveAnterior = $persona->password;

        $this->artisan('abaco:crear-administrador', ['email' => 'existente@abaco.test', 'nombre' => 'Otro nombre'])->assertFailed();

        $this->assertSame($claveAnterior, $persona->fresh()->password);
        $this->assertSame('trabajador', $persona->fresh()->role);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_el_bootstrap_no_acepta_una_clave_corta_ni_crea_la_cuenta(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->artisan('abaco:crear-administrador', ['email' => 'administracion@abaco.test', 'nombre' => 'Administración'])
            ->expectsQuestion('Contraseña (mínimo 12 caracteres)', 'corta')
            ->expectsQuestion('Repite la contraseña', 'corta')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_sin_interaccion_no_hay_password_por_defecto(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->artisan('abaco:crear-administrador', ['email' => 'administracion@abaco.test', 'nombre' => 'Administración', '--no-interaction' => true])->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
