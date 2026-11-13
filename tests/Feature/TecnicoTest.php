<?php

namespace Tests\Feature;

use App\Models\AccionTecnica;
use App\Models\Jornada;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TecnicoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-07 10:45:00', 'Europe/Madrid'));
    }

    public function test_el_tecnico_ficha_como_cualquiera_y_ve_el_enlace_al_sistema(): void
    {
        $tecnico = User::factory()->tecnico()->create();

        $this->post(route('login'), ['email' => $tecnico->email, 'password' => 'password'])
            ->assertRedirect(route('jornada'));

        $this->actingAs($tecnico)->get(route('jornada'))->assertOk()->assertSee(route('tecnico.index'), false);

        $this->actingAs($tecnico)->post(route('jornada.entrada'));

        $this->assertDatabaseHas('jornadas', ['user_id' => $tecnico->id]);
    }

    public function test_sistema_ofrece_elegir_una_base_guardada_o_un_archivo(): void
    {
        $this->actingAs(User::factory()->tecnico()->create())
            ->get(route('tecnico.index'))
            ->assertOk()
            ->assertSee('Elegir la base de datos', false)
            ->assertSee('Un archivo nuevo', false)
            ->assertSee('Quiero sustituir la base actual por esta.', false)
            ->assertDontSee('Esta acción de muestra no modifica la base de datos real.', false);
    }

    public function test_solo_el_tecnico_entra_en_sistema(): void
    {
        foreach ([User::factory()->create(), User::factory()->jefa()->create()] as $persona) {
            $this->actingAs($persona)->get(route('tecnico.index'))
                ->assertRedirect(route('jornada'))
                ->assertSessionHas('aviso');
            $this->actingAs($persona)->post(route('tecnico.copia'))->assertRedirect(route('jornada'));
            $this->actingAs($persona)->post(route('tecnico.actualizar'), ['confirmo' => '1'])->assertRedirect(route('jornada'));
            $this->actingAs($persona)->post(route('tecnico.restaurar'), ['confirmo' => '1'])->assertRedirect(route('jornada'));

            $this->assertDatabaseCount('acciones_tecnicas', 0);
        }
    }

    public function test_el_tecnico_no_entra_en_las_pantallas_de_la_jefa(): void
    {
        $tecnico = User::factory()->tecnico()->create();

        $this->actingAs($tecnico)->get(route('equipo.index'))->assertRedirect(route('jornada'));
        $this->actingAs($tecnico)->get(route('jefe.index'))->assertRedirect(route('jornada'));
    }

    public function test_el_panel_enseña_la_base_de_datos_sin_datos_de_las_personas(): void
    {
        $ana = User::factory()->create(['name' => 'Ana Reservada', 'email' => 'ana.reservada@abaco.test']);
        Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-06']);

        $respuesta = $this->actingAs(User::factory()->tecnico()->create())
            ->get(route('tecnico.index'))
            ->assertOk()
            ->assertSee('Base de datos activa')
            ->assertSee('Todo está al día.')
            ->assertSee('jornadas')
            ->assertSee('1 fila')
            ->assertDontSee('Ana Reservada')
            ->assertDontSee('ana.reservada@abaco.test');

        $base = $respuesta->viewData('base');
        $this->assertTrue($base['responde']);
        $this->assertTrue($base['integridad']['correcta']);
        $this->assertSame([], $base['pendientes']);
    }

    public function test_el_panel_lista_los_cambios_pendientes(): void
    {
        DB::table('migrations')->where('migration', 'like', '%create_acciones_tecnicas_table')->delete();

        $respuesta = $this->actingAs(User::factory()->tecnico()->create())
            ->get(route('tecnico.index'))
            ->assertOk()
            ->assertSee('Hay 1 cambios sin aplicar');

        $this->assertCount(1, $respuesta->viewData('base')['pendientes']);
    }

    public function test_actualizar_exige_confirmar(): void
    {
        $this->actingAs(User::factory()->tecnico()->create())
            ->post(route('tecnico.actualizar'))
            ->assertSessionHasErrors('confirmo');

        $this->assertDatabaseCount('acciones_tecnicas', 0);
    }

    public function test_si_no_hay_nada_pendiente_lo_dice_y_lo_anota(): void
    {
        $tecnico = User::factory()->tecnico()->create();

        $this->actingAs($tecnico)
            ->post(route('tecnico.actualizar'), ['confirmo' => '1'])
            ->assertSessionHas('ok', 'Todo está al día. No había nada que aplicar.');

        $this->assertDatabaseHas('acciones_tecnicas', [
            'user_id' => $tecnico->id,
            'accion' => AccionTecnica::ACTUALIZACION,
        ]);
    }

    public function test_la_copia_se_rechaza_si_la_base_no_es_un_archivo(): void
    {
        $this->actingAs(User::factory()->tecnico()->create())
            ->post(route('tecnico.copia'))
            ->assertSessionHas('aviso');

        $this->assertDatabaseCount('acciones_tecnicas', 0);
    }
}
