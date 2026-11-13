<?php

namespace Tests\Feature;

use App\Models\Ausencia;
use App\Models\Centro;
use App\Models\SolicitudVacaciones;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CoberturaSolicitudesTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_solicitud_avisa_si_al_aceptarla_la_sede_se_queda_corta(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 09:00:00', 'Europe/Madrid'));
        $madrid = Centro::query()->where('name', 'Madrid')->firstOrFail();
        $madrid->update(['minimo_presencia' => 2]);
        $jefa = User::factory()->jefa()->create(['centro_id' => null]);
        $ana = User::factory()->create(['name' => 'Ana García', 'centro_id' => $madrid->id]);
        $luis = User::factory()->create(['name' => 'Luis Pérez', 'centro_id' => $madrid->id]);
        $nora = User::factory()->create(['name' => 'Nora Díaz', 'centro_id' => $madrid->id]);
        Ausencia::create(['user_id' => $nora->id, 'absence_date' => '2026-11-17', 'tipo' => 'vacaciones']);
        SolicitudVacaciones::create(['user_id' => $ana->id, 'starts_on' => '2026-11-17', 'ends_on' => '2026-11-17', 'estado' => SolicitudVacaciones::PENDIENTE]);
        SolicitudVacaciones::create(['user_id' => $luis->id, 'starts_on' => '2026-11-17', 'ends_on' => '2026-11-17', 'estado' => SolicitudVacaciones::PENDIENTE]);

        $this->actingAs($jefa)->get(route('gestion.index'))
            ->assertOk()
            ->assertSee(route('gestion.cobertura'), false)
            ->assertDontSee('Queda 1 de 3 el 17/11 en Madrid', false);

        $respuesta = $this->actingAs($jefa)->get(route('gestion.cobertura'));

        $respuesta->assertOk()
            ->assertSee('Queda 1 de 3 el 17/11 en Madrid. El mínimo es 2.', false)
            ->assertSee('Mar 17/11 · queda 1 de 3 · fuera: Nora Díaz · también pendiente: Luis Pérez', false)
            ->assertSee('Mar 17/11 · queda 1 de 3 · fuera: Nora Díaz · también pendiente: Ana García', false)
            ->assertSee('17/11 · quedan 2 de 3', false)
            ->assertSee('cobertura-justo tiene-pendiente', false);
    }

    public function test_cabe_cuando_aceptarla_deja_gente_por_encima_del_minimo(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 09:00:00', 'Europe/Madrid'));
        $madrid = Centro::query()->where('name', 'Madrid')->firstOrFail();
        $jefa = User::factory()->jefa()->create(['centro_id' => null]);
        $ana = User::factory()->create(['name' => 'Ana García', 'centro_id' => $madrid->id]);
        User::factory()->create(['name' => 'Luis Pérez', 'centro_id' => $madrid->id]);
        User::factory()->create(['name' => 'Nora Díaz', 'centro_id' => $madrid->id]);
        SolicitudVacaciones::create(['user_id' => $ana->id, 'starts_on' => '2026-11-17', 'ends_on' => '2026-11-17', 'estado' => SolicitudVacaciones::PENDIENTE]);

        $this->actingAs($jefa)->get(route('gestion.cobertura'))
            ->assertOk()
            ->assertSee('Cabe en la sede.');
    }

    public function test_la_jefa_guarda_el_minimo_de_la_sede(): void
    {
        $madrid = Centro::query()->where('name', 'Madrid')->firstOrFail();
        $jefa = User::factory()->jefa()->create();

        $this->actingAs($jefa)
            ->patch(route('equipo.centros.minimo', $madrid), ['minimo_presencia' => 4])
            ->assertRedirect()
            ->assertSessionHas('ok', 'El mínimo de Madrid es 4 personas.');

        $this->assertSame(4, $madrid->refresh()->minimo_presencia);
    }
}
