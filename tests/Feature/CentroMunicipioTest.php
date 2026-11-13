<?php

namespace Tests\Feature;

use App\Models\Centro;
use App\Models\Festivo;
use App\Models\ImportacionCalendario;
use App\Models\User;
use App\Services\CatalogoMunicipios;
use App\Services\NagerFestivos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CentroMunicipioTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_alta_resuelve_ine_y_no_confia_en_provincia_o_comunidad_enviadas(): void
    {
        $jefa = User::factory()->jefa()->create();

        $this->actingAs($jefa)->post(route('equipo.centros.store'), [
            'name' => 'Sede Álava', 'municipio_ine' => '01001',
            'municipality' => 'Texto falso', 'community' => 'Autonomía falsa', 'province' => 'Provincia falsa',
        ])->assertSessionHasNoErrors()->assertSessionHas('ok');

        $sede = Centro::query()->where('name', 'Sede Álava')->firstOrFail();
        $this->assertSame('01001', $sede->municipio_ine);
        $this->assertSame('Alegría-Dulantzi', $sede->municipality);
        $this->assertSame('Araba/Álava', $sede->province);
        $this->assertSame('01', $sede->province_code);
        $this->assertSame('País Vasco', $sede->community);
        $this->assertSame('ES-PV', $sede->comunidad_codigo);
        Http::assertNothingSent();
    }

    public function test_cambiar_el_municipio_pide_la_fecha_y_no_toca_la_sede_sin_ella(): void
    {
        $jefa = User::factory()->jefa()->create();
        $sede = $jefa->sede;
        $this->calendarioConfirmado($sede, 2026);

        $this->actingAs($jefa)->patch(route('equipo.centros.update', $sede), [
            'name' => 'Sede València', 'municipio_ine' => '46250',
        ])->assertSessionHasErrors('vigente_desde');

        $this->assertSame('Madrid', $sede->fresh()->name);
        $this->assertSame($sede->id.':2026', ImportacionCalendario::query()->where('centro_id', $sede->id)->sole()->clave_activa);
        Http::assertNothingSent();
    }

    public function test_al_aceptar_la_fecha_guarda_los_festivos_del_municipio_nuevo_desde_ese_dia(): void
    {
        $jefa = User::factory()->jefa()->create();
        $sede = $jefa->sede;
        $this->calendarioConfirmado($sede, 2026);
        $datos = fn (string $nombre): array => json_decode(file_get_contents(base_path('tests/Fixtures/Calendario/'.$nombre.'.json')), true, flags: JSON_THROW_ON_ERROR);
        Http::fake([
            'https://festivos.io/v1/2026/municipio/40194.json' => Http::response($datos('segovia-2026')),
            'https://www.boe.es/diario_boe/xml.php?id=BOE-A-2025-21667' => Http::response('', 503),
        ]);

        $this->actingAs($jefa)->patch(route('equipo.centros.update', $sede), [
            'name' => 'Sede Segovia', 'municipio_ine' => '40194', 'vigente_desde' => '2026-02-01',
        ])->assertSessionHasNoErrors()->assertSessionHas('ok')
            ->assertRedirect(route('gestion.calendario.mostrar', ['centro' => $sede, 'anio' => 2026]));

        $this->get(route('gestion.calendario.mostrar', ['centro' => $sede, 'anio' => 2026]))
            ->assertOk()
            ->assertSee('Segovia')
            ->assertSee('San Pedro')
            ->assertSee('01/02–31/12');

        $sede->refresh();
        $this->assertSame('40194', $sede->municipio_ine);
        $this->assertSame('Segovia', $sede->municipality);
        $this->assertSame('Segovia', $jefa->fresh()->municipality);
        $activo = ImportacionCalendario::query()->where('clave_activa', $sede->id.':2026')->firstOrFail();
        $this->assertSame('2026-02-01', $activo->vigente_desde->toDateString());
        $this->assertContains('San Pedro', array_column($activo->festivos, 'name'));
        $tramos = app(\App\Services\VigenciasCalendario::class)->delAnio($sede, 2026);
        $this->assertSame('2026-01-31', $tramos->first()->hasta->toDateString());
        $this->assertSame('Segovia', $tramos->last()->importacion->municipio);
        $this->assertSame('2026-02-01', $tramos->last()->desde->toDateString());
        $this->actingAs($jefa->fresh())->get(route('calendario', ['anio' => 2026, 'mes' => 6]))
            ->assertOk()->assertSee('San Pedro');
        $this->get(route('calendario', ['anio' => 2026, 'mes' => 1]))
            ->assertOk()->assertDontSee('Año Nuevo');
    }

    public function test_renombrar_con_el_mismo_ine_conserva_el_calendario(): void
    {
        $jefa = User::factory()->jefa()->create();
        $sede = $jefa->sede;
        $sede->update(['municipio_ine' => '28079', 'municipality' => 'Madrid', 'community' => 'Comunidad de Madrid']);
        $this->calendarioConfirmado($sede, 2026);
        $original = ImportacionCalendario::query()->where('centro_id', $sede->id)->sole();

        $this->actingAs($jefa)->patch(route('equipo.centros.update', $sede), [
            'name' => 'Nombre nuevo', 'municipio_ine' => '28079',
        ])->assertSessionHasNoErrors()->assertSessionHas('ok');

        $this->assertSame($sede->id.':2026', $original->fresh()->clave_activa);
        $this->assertSame(1, ImportacionCalendario::query()->where('centro_id', $sede->id)->count());
    }

    public function test_el_formulario_legacy_no_conserva_la_identidad_ine_de_otro_territorio(): void
    {
        $jefa = User::factory()->jefa()->create();
        $sede = $jefa->sede;
        $sede->update(['municipio_ine' => '28079', 'province' => 'Madrid', 'province_code' => '28', 'comunidad_codigo' => 'ES-MD']);
        $this->calendarioConfirmado($sede, 2026);

        $this->actingAs($jefa)->patch(route('equipo.centros.update', $sede), [
            'name' => 'Sede por revisar', 'municipality' => 'Getafe', 'community' => 'Comunidad de Madrid',
        ])->assertSessionHasNoErrors()->assertSessionHas('ok');

        $sede->refresh();
        $this->assertNull($sede->municipio_ine);
        $this->assertNull($sede->province);
        $this->assertNull($sede->comunidad_codigo);
        $this->assertSame(0, ImportacionCalendario::query()->where('centro_id', $sede->id)->whereNotNull('clave_activa')->count());
    }

    public function test_un_ine_inexistente_no_crea_ni_cambia_sedes(): void
    {
        $jefa = User::factory()->jefa()->create();
        $total = Centro::query()->count();

        $this->actingAs($jefa)->post(route('equipo.centros.store'), [
            'name' => 'Sede inexistente', 'municipio_ine' => '99999',
        ])->assertSessionHasErrors('municipio_ine');
        $this->patch(route('equipo.centros.update', $jefa->sede), [
            'name' => 'No se debe guardar', 'municipio_ine' => '99999',
        ])->assertSessionHasErrors('municipio_ine');

        $this->assertSame($total, Centro::query()->count());
        $this->assertSame('Madrid', $jefa->sede->fresh()->name);
        Http::assertNothingSent();
    }

    public function test_cambiar_sede_conserva_festivos_manuales_pasados_sin_inventar_cobertura(): void
    {
        $this->travelTo(Carbon::parse('2026-10-30 10:00:00', 'Europe/Madrid'));
        $jefa = User::factory()->jefa()->create();
        $sede = $jefa->sede;
        Festivo::create(['holiday_date' => '2026-01-10', 'name' => 'Madrid manual enero', 'ambito' => 'pueblo', 'municipality' => 'Madrid', 'centro_id' => $sede->id]);
        Festivo::create(['holiday_date' => '2026-11-10', 'name' => 'Madrid manual futuro', 'ambito' => 'pueblo', 'municipality' => 'Madrid', 'centro_id' => $sede->id]);
        Festivo::create(['holiday_date' => '2026-11-11', 'name' => 'Segovia manual futuro', 'ambito' => 'pueblo', 'municipality' => 'Segovia']);

        Http::fake(['https://festivos.io/v1/2026/municipio/40194.json' => Http::response([], 503)]);

        $this->actingAs($jefa)->patch(route('equipo.centros.update', $sede), [
            'name' => 'Sede Segovia', 'municipio_ine' => '40194', 'vigente_desde' => '2026-10-30',
        ])->assertSessionHasNoErrors()->assertSessionHas('aviso');

        $this->actingAs($jefa->fresh());
        $this->get(route('calendario', ['anio' => 2026, 'mes' => 1]))->assertOk()->assertSee('Madrid manual enero');
        $this->get(route('calendario', ['anio' => 2026, 'mes' => 11]))->assertOk()
            ->assertSee('Segovia manual futuro')->assertDontSee('Madrid manual futuro');
        $this->assertDatabaseHas('importaciones_calendario', ['centro_id' => $sede->id, 'estado' => 'contexto_manual', 'municipio' => 'Madrid']);
        $this->assertFalse(app(NagerFestivos::class)->coberturaConfirmada($sede->fresh(), 2026, Carbon::parse('2026-01-10')));
        $this->assertFalse(app(NagerFestivos::class)->coberturaConfirmada($sede->fresh(), 2026, Carbon::parse('2026-11-11')));
        $this->assertSame(3, Festivo::query()->count());
    }

    public function test_gestion_muestra_selector_y_procedencia_del_catalogo_local(): void
    {
        $jefa = User::factory()->jefa()->create();

        $this->actingAs($jefa)->get(route('gestion.index'))->assertOk()
            ->assertSee('data-sede-municipio', false)
            ->assertSee('data-sede-calendario-dialogo', false)
            ->assertSee('Empieza el')
            ->assertSee('Las fechas anteriores mantienen su calendario.')
            ->assertSee('name="municipio_ine"', false)
            ->assertSee('8.132 municipios')
            ->assertSee(app(CatalogoMunicipios::class)->estado()['referencia'], false);

        Http::assertNothingSent();
    }
}
