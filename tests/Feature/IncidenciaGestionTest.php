<?php

namespace Tests\Feature;

use App\Models\Incidencia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidenciaGestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_motivo_exige_minimo_y_maximo(): void
    {
        $ana = User::factory()->create();

        $this->actingAs($ana)->post(route('jornada.incidencia'), [
            'motivo' => 'Corto',
        ])->assertSessionHasErrors('motivo');
        $this->assertDatabaseCount('incidencias', 0);

        $this->actingAs($ana)->post(route('jornada.incidencia'), [
            'motivo' => str_repeat('a', Incidencia::CUERPO_MAXIMO + 1),
        ])->assertSessionHasErrors('motivo');
        $this->assertDatabaseCount('incidencias', 0);

        $this->actingAs($ana)->post(route('jornada.incidencia'), [
            'motivo' => '   aaa   ',
        ])->assertSessionHasErrors('motivo');
        $this->assertDatabaseCount('incidencias', 0);

        $this->actingAs($ana)->post(route('jornada.incidencia'), [
            'motivo' => 'Se cayó la red',
        ])->assertSessionHas('ok');

        $this->assertDatabaseHas('incidencias', [
            'user_id' => $ana->id,
            'cuerpo' => 'Se cayó la red',
            'estado' => Incidencia::PENDIENTE,
        ]);
    }

    public function test_un_mensaje_pasa_la_incidencia_a_en_curso(): void
    {
        $ana = User::factory()->create();
        $marta = User::factory()->jefa()->create();
        $incidencia = Incidencia::create([
            'user_id' => $ana->id,
            'cuerpo' => 'Se cayó la red del centro.',
        ]);

        $this->actingAs($marta)->post(route('incidencias.mensaje', $incidencia), [
            'cuerpo' => 'Ya lo miro',
        ])->assertSessionHas('ok');

        $this->assertSame(Incidencia::EN_CURSO, $incidencia->fresh()->estado);
    }

    public function test_la_jefa_marca_en_curso_sin_escribir(): void
    {
        $ana = User::factory()->create();
        $marta = User::factory()->jefa()->create();
        $incidencia = Incidencia::create([
            'user_id' => $ana->id,
            'cuerpo' => 'Se cayó la red del centro.',
        ]);

        $this->actingAs($marta)->get(route('incidencias.show', $incidencia))
            ->assertOk()
            ->assertSee('Pendiente', false)
            ->assertSee('En curso', false)
            ->assertSee('Resolver', false);

        $this->actingAs($marta)->post(route('incidencias.curso', $incidencia))
            ->assertSessionHas('ok', 'En curso.');

        $this->assertSame(Incidencia::EN_CURSO, $incidencia->fresh()->estado);

        $this->actingAs($marta)->get(route('incidencias.show', $incidencia))
            ->assertSee('En curso', false)
            ->assertDontSee('>En curso<', false);
    }

    public function test_las_resueltas_salen_del_listado_y_van_al_archivo(): void
    {
        $ana = User::factory()->create();
        $marta = User::factory()->jefa()->create();
        $abierta = Incidencia::create([
            'user_id' => $ana->id,
            'cuerpo' => 'Sigue sin red en la sala.',
        ]);
        $resuelta = Incidencia::create([
            'user_id' => $ana->id,
            'cuerpo' => 'El acceso ya funciona otra vez.',
            'estado' => Incidencia::RESUELTA,
            'resuelta_at' => now(),
        ]);

        $this->actingAs($marta)->get(route('incidencias.index'))
            ->assertOk()
            ->assertSee('Activas', false)
            ->assertSee('Archivo', false)
            ->assertSee('Sigue sin red en la sala.', false)
            ->assertDontSee('El acceso ya funciona otra vez.', false)
            ->assertSee('Pendiente', false);

        $this->actingAs($marta)->get(route('incidencias.index', ['archivo' => 1]))
            ->assertOk()
            ->assertSee('El acceso ya funciona otra vez.', false)
            ->assertDontSee('Sigue sin red en la sala.', false)
            ->assertSee('Resuelta', false);

        $this->actingAs($marta)->post(route('incidencias.resolver', $abierta))
            ->assertRedirect(route('incidencias.index'))
            ->assertSessionHas('ok', 'Resuelta. Queda en el archivo.');

        $this->actingAs($marta)->get(route('incidencias.index'))
            ->assertDontSee('Sigue sin red en la sala.', false)
            ->assertSee('No hay incidencias activas.', false);

        $this->actingAs($marta)->get(route('incidencias.index', ['archivo' => 1]))
            ->assertSee('Sigue sin red en la sala.', false)
            ->assertSee('El acceso ya funciona otra vez.', false);

        $this->assertSame(Incidencia::RESUELTA, $abierta->fresh()->estado);
        $this->assertNotNull($abierta->fresh()->resuelta_at);
    }

    public function test_la_trabajadora_no_ve_el_archivo_de_direccion(): void
    {
        $ana = User::factory()->create();

        $this->actingAs($ana)->get(route('incidencias.index'))
            ->assertRedirect(route('jornada'));
        $this->actingAs($ana)->post(route('incidencias.curso', Incidencia::create([
            'user_id' => $ana->id,
            'cuerpo' => 'Se cayó la red del centro.',
        ])))->assertRedirect(route('jornada'));
    }
}
