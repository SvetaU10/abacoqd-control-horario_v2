<?php

namespace Tests\Feature;

use App\Models\Centro;
use App\Models\SolicitudVacaciones;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GestionPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_ultima_solicitud_sigue_accesible_con_mas_de_cien_pendientes(): void
    {
        $this->travelTo(Carbon::parse('2026-10-30 10:00:00', 'Europe/Madrid'));
        $jefa = User::factory()->jefa()->create();
        $persona = User::factory()->create();
        $primera = null;
        foreach (range(1, 101) as $numero) {
            $fecha = Carbon::parse('2027-01-01')->addDays($numero)->toDateString();
            $solicitud = SolicitudVacaciones::create(['user_id' => $persona->id, 'starts_on' => $fecha, 'ends_on' => $fecha, 'estado' => SolicitudVacaciones::PENDIENTE]);
            $primera ??= $solicitud;
        }

        $respuesta = $this->actingAs($jefa)->get(route('gestion.cobertura', ['pagina_vacaciones' => 6]));

        $respuesta->assertOk()->assertSee($solicitud->periodoTexto())->assertSee('Página 6 de 6')->assertSee('101 solicitudes')
            ->assertDontSee($primera->periodoTexto())->assertViewHas('solicitudes', fn ($paginas) => $paginas->count() === 1 && $paginas->total() === 101);
    }

    public function test_una_sede_y_solicitudes_vacias_muestran_la_configuracion_inicial_limpia(): void
    {
        Centro::query()->delete();
        $centro = Centro::create(['name' => 'Principal', 'municipality' => 'Madrid', 'community' => 'Comunidad de Madrid', 'municipio_ine' => '28079']);
        $jefa = User::factory()->jefa()->create(['centro_id' => $centro->id]);

        $respuesta = $this->actingAs($jefa)->get(route('gestion.index'));

        $respuesta->assertOk()->assertSee('Sede principal')->assertSee('Principal')->assertSee('No hay solicitudes pendientes.')
            ->assertSee('Gestionar calendario')->assertDontSee('calendarios sincronizados')->assertDontSee('Sede Getafe');
    }
}
