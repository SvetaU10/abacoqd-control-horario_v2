<?php

namespace Tests\Feature;

use App\Models\Jornada;
use App\Models\User;
use App\Services\PlantillaDemo;
use App\TipoJornada;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PlantillaDemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_seeder_carga_la_misma_plantilla_y_se_puede_repetir(): void
    {
        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->assertSame(1, User::query()->where('email', 'marta.ruiz@abaco.test')->count());
        $this->assertSame(1, User::query()->where('email', 'ana.lopez@abaco.test')->count());
        $this->assertSame(1, User::query()->where('email', 'lucia.vega@abaco.test')->count());
        $this->assertSame(1, User::query()->where('email', 'carmen.ortega@abaco.test')->count());

        $lucia = User::query()->where('email', 'lucia.vega@abaco.test')->first();
        $this->assertSame('Barcelona', $lucia->sede->name);
        $this->assertSame('Barcelona', $lucia->municipality);
        $this->assertSame('trabajador', $lucia->role);
    }

    public function test_el_seeder_no_borra_fichajes_ni_cambia_la_clave(): void
    {
        $this->seed(DemoSeeder::class);
        $ana = User::query()->where('email', 'ana.lopez@abaco.test')->first();
        $ana->update(['password' => 'ClaveQueYaTenia', 'role' => 'tecnico', 'name' => 'Nombre conservado']);
        Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-07']);

        $this->seed(DemoSeeder::class);

        $this->assertTrue($ana->fresh()->jornadas()->whereDate('work_date', '2026-10-07')->exists());
        $this->assertTrue(Hash::check('ClaveQueYaTenia', $ana->fresh()->password));
        $this->assertSame('tecnico', $ana->fresh()->role);
        $this->assertSame('Nombre conservado', $ana->fresh()->name);
    }

    public function test_un_alta_real_no_se_exporta_al_archivo_de_la_demo(): void
    {
        $archivo = storage_path('framework/testing/personas-alta.json');
        $origen = database_path('seeders/data/personas.json');
        File::ensureDirectoryExists(dirname($archivo));
        copy($origen, $archivo);
        $this->app->instance(PlantillaDemo::class, new PlantillaDemo($archivo));
        $contenido = File::get($archivo);
        $jefa = User::factory()->jefa()->create();
        $this->app->instance('env', 'local');

        $this->actingAs($jefa)->withSession(['_token' => 'prueba-token'])->post(route('equipo.store'), [
            '_token' => 'prueba-token',
            'name' => 'Nuria Vega',
            'email' => 'nuria.vega@abaco.test',
            'password' => 'Jornada2026',
            'starts_on' => '2026-10-13',
            'duracion' => 'indefinido', 'centro' => 'Sevilla', 'tipo' => TipoJornada::Continua->value,
        ])->assertSessionHas('ok');

        $alta = User::query()->where('email', 'nuria.vega@abaco.test')->first();
        $this->assertNotNull($alta);
        $this->assertSame('Sevilla', $alta->sede->name);
        $this->assertSame('Sevilla', $alta->municipality);
        $this->assertSame($contenido, File::get($archivo));
        unlink($archivo);
    }
}
