<?php

namespace Tests\Feature;

use App\Models\Centro;
use App\Models\ImportacionCalendario;
use App\Models\User;
use App\Services\CalendarioMunicipal;
use App\Services\NagerFestivos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImportacionCalendarioHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_solo_la_jefa_puede_cargar_o_consultar_municipios(): void
    {
        $centro = Centro::query()->where('name', 'Madrid')->firstOrFail();
        $persona = User::factory()->create();
        Http::fake();

        $this->get(route('gestion.calendario.mostrar', $centro))->assertRedirect(route('login'));
        $this->actingAs($persona)->get(route('gestion.calendario.mostrar', $centro))->assertRedirect(route('jornada'))
            ->assertSessionHas('aviso', 'Esta página es de el responsable del equipo.');
        $this->getJson(route('gestion.municipios', ['buscar' => 'Madrid']))->assertRedirect(route('jornada'));
        $this->post(route('gestion.calendario.cargar', $centro), ['anio' => 2026, 'municipio_ine' => '28079'])->assertRedirect(route('jornada'));
        Http::assertNothingSent();
        $this->assertDatabaseCount('importaciones_calendario', 0);
    }

    public function test_la_pantalla_muestra_ano_municipio_y_centro_sin_exigir_provincia(): void
    {
        $jefa = $this->jefa();
        $this->fuentesMadrid();

        $this->actingAs($jefa)->get(route('gestion.calendario.mostrar', ['centro' => $jefa->centro_id, 'anio' => 2027]))
            ->assertOk()->assertSee('Calendario laboral')->assertSee('Cargar / actualizar')
            ->assertSee('name="anio"', false)->assertSee('name="municipio_ine"', false)
            ->assertSee('Madrid · Madrid')->assertDontSee('name="provincia"', false);

        Http::assertNothingSent();
    }

    public function test_el_buscador_devuelve_el_codigo_y_territorio_del_municipio(): void
    {
        $jefa = $this->jefa();
        $this->fuentesMadrid();

        $respuesta = $this->actingAs($jefa)->getJson(route('gestion.municipios', ['buscar' => 'Madrid']))->assertOk();

        $this->assertSame('28079', $respuesta->json('municipios.0.ine'));
        $this->assertSame('Madrid', $respuesta->json('municipios.0.provincia'));
        $this->assertSame('ES-MD', $respuesta->json('municipios.0.comunidad_codigo'));
        Http::assertNothingSent();
    }

    public function test_la_carga_guarda_y_muestra_el_calendario_oficial_comprobado(): void
    {
        $jefa = $this->jefa();
        $this->fuentesMadrid();

        $this->actingAs($jefa)->post(route('gestion.calendario.cargar', $jefa->centro_id), ['anio' => 2026, 'municipio_ine' => '28079'])
            ->assertRedirect(route('gestion.calendario.mostrar', ['centro' => $jefa->centro_id, 'anio' => 2026]))
            ->assertSessionHas('ok');

        $activo = ImportacionCalendario::query()->whereNotNull('clave_activa')->sole();
        $this->assertSame('cargado', $activo->estado);
        $this->assertSame(14, count($activo->festivos));
        $this->assertTrue(app(NagerFestivos::class)->es($jefa->sede, Carbon::parse('2026-11-09')));
        $this->get(route('gestion.calendario.mostrar', ['centro' => $jefa->centro_id, 'anio' => 2026]))
            ->assertOk()->assertSee('Calendario activo')->assertSee('14 fechas')->assertSee('Almudena');
        $this->get(route('calendario', ['anio' => 2026, 'mes' => 11]))->assertOk()->assertSee('Almudena');
    }

    public function test_el_ano_parcial_se_explica_sin_activar_ni_borrar_el_anterior(): void
    {
        $jefa = $this->jefa();
        $this->fuentesMadrid();
        $this->actingAs($jefa)->post(route('gestion.calendario.cargar', $jefa->centro_id), ['anio' => 2026, 'municipio_ine' => '28079']);

        $this->post(route('gestion.calendario.cargar', $jefa->centro_id), ['anio' => 2027, 'municipio_ine' => '28079'])->assertSessionHas('aviso');

        $this->assertDatabaseHas('importaciones_calendario', ['anio' => 2027, 'estado' => 'parcial', 'clave_activa' => null]);
        $this->assertSame(1, ImportacionCalendario::query()->whereNotNull('clave_activa')->count());
        $this->get(route('gestion.calendario.mostrar', ['centro' => $jefa->centro_id, 'anio' => 2027]))
            ->assertOk()->assertSee('Parcial')->assertSee('Aún no hay un calendario completo confirmado');
    }

    public function test_el_formulario_rechaza_un_ano_o_municipio_invalido_antes_de_consultar(): void
    {
        $jefa = $this->jefa();
        Http::fake();

        $this->actingAs($jefa)->post(route('gestion.calendario.cargar', $jefa->centro_id), ['anio' => 'mañana', 'municipio_ine' => 'Madrid'])
            ->assertSessionHasErrors(['anio', 'municipio_ine']);

        Http::assertNothingSent();
        $this->assertDatabaseCount('importaciones_calendario', 0);
    }

    public function test_no_se_confirma_un_candidato_sin_revision_ni_desde_otro_centro(): void
    {
        $jefa = $this->jefa();
        $this->fuentesMadrid();
        Http::fake(['festivos.io/v1/2026/municipio/40194.json' => Http::response($this->datos('segovia-2026.json'))]);
        $this->actingAs($jefa)->post(route('gestion.calendario.cargar', $jefa->centro_id), ['anio' => 2026, 'municipio_ine' => '40194']);
        $candidato = ImportacionCalendario::query()->latest('id')->firstOrFail();
        $otroCentro = Centro::query()->where('id', '!=', $jefa->centro_id)->firstOrFail();

        $this->post(route('gestion.calendario.confirmar', ['centro' => $jefa->centro_id, 'importacion' => $candidato]))->assertSessionHasErrors('revision');
        $this->post(route('gestion.calendario.confirmar', ['centro' => $otroCentro, 'importacion' => $candidato]), ['revision' => '1'])->assertNotFound();

        $this->assertSame(0, ImportacionCalendario::query()->whereNotNull('clave_activa')->count());
        $this->post(route('gestion.calendario.confirmar', ['centro' => $jefa->centro_id, 'importacion' => $candidato]), ['revision' => '1'])->assertSessionHas('ok');
        $this->assertSame(1, ImportacionCalendario::query()->whereNotNull('clave_activa')->count());
    }

    public function test_un_codigo_ine_inexistente_devuelve_un_error_recuperable_sin_importar(): void
    {
        $jefa = $this->jefa();
        $this->fuentesMadrid();
        $pantalla = route('gestion.calendario.mostrar', ['centro' => $jefa->centro_id, 'anio' => 2026]);

        $this->actingAs($jefa)->from($pantalla)
            ->post(route('gestion.calendario.cargar', $jefa->centro_id), ['anio' => 2026, 'municipio_ine' => '99999'])
            ->assertRedirect($pantalla)->assertSessionHasErrors(['municipio_ine' => 'Ese código de municipio no está en el catálogo local.'])
            ->assertSessionHasInput('municipio_ine', '99999');

        $this->assertDatabaseCount('importaciones_calendario', 0);
        Http::assertNothingSent();
    }

    public function test_confirmar_dos_veces_el_mismo_borrador_avisa_y_conserva_el_calendario_activo(): void
    {
        $jefa = $this->jefa();
        $this->fuentesMadrid();
        Http::fake(['festivos.io/v1/2026/municipio/40194.json' => Http::response($this->datos('segovia-2026.json'))]);
        $pantalla = route('gestion.calendario.mostrar', ['centro' => $jefa->centro_id, 'anio' => 2026]);
        $this->actingAs($jefa)->post(route('gestion.calendario.cargar', $jefa->centro_id), ['anio' => 2026, 'municipio_ine' => '40194']);
        $borrador = ImportacionCalendario::query()->sole();
        $confirmar = route('gestion.calendario.confirmar', ['centro' => $jefa->centro_id, 'importacion' => $borrador]);
        $this->post($confirmar, ['revision' => '1'])->assertRedirect($pantalla)->assertSessionHas('ok');
        $activo = ImportacionCalendario::query()->whereNotNull('clave_activa')->sole();

        $this->from($pantalla)->post($confirmar, ['revision' => '1'])->assertRedirect($pantalla)
            ->assertSessionHas('aviso', 'Esta consulta no se puede confirmar. Busca de nuevo el calendario y revisa sus fuentes.');

        $this->assertSame($activo->id, ImportacionCalendario::query()->whereNotNull('clave_activa')->sole()->id);
        $this->assertSame($activo->festivos, $activo->fresh()->festivos);
        $this->assertDatabaseCount('importaciones_calendario', 2);
        Http::assertSentCount(2);
    }

    public function test_confirmar_un_borrador_obsoleto_avisa_y_conserva_la_version_mas_reciente(): void
    {
        $jefa = $this->jefa();
        $this->fuentesMadrid();
        Http::fake(['festivos.io/v1/2026/municipio/40194.json' => Http::response($this->datos('segovia-2026.json'))]);
        $pantalla = route('gestion.calendario.mostrar', ['centro' => $jefa->centro_id, 'anio' => 2026]);
        $this->actingAs($jefa)->post(route('gestion.calendario.cargar', $jefa->centro_id), ['anio' => 2026, 'municipio_ine' => '40194']);
        $borrador = ImportacionCalendario::query()->sole();
        $this->post(route('gestion.calendario.cargar', $jefa->centro_id), ['anio' => 2026, 'municipio_ine' => '28079'])->assertSessionHas('ok');
        $activo = ImportacionCalendario::query()->whereNotNull('clave_activa')->sole();

        $this->from($pantalla)->post(route('gestion.calendario.confirmar', ['centro' => $jefa->centro_id, 'importacion' => $borrador]), ['revision' => '1'])
            ->assertRedirect($pantalla)->assertSessionHas('aviso', 'Esta consulta no se puede confirmar. Busca de nuevo el calendario y revisa sus fuentes.');

        $this->assertSame($activo->id, ImportacionCalendario::query()->whereNotNull('clave_activa')->sole()->id);
        $this->assertSame('28079', $activo->fresh()->municipio_ine);
        $this->assertSame($activo->festivos, $activo->fresh()->festivos);
        $this->assertDatabaseCount('importaciones_calendario', 2);
        $this->assertTrue(app(NagerFestivos::class)->coberturaConfirmada($jefa->sede, 2026));
    }

    public function test_el_catalogo_remoto_caido_no_impide_buscar_en_la_copia_local(): void
    {
        $jefa = $this->jefa();
        Http::fake([CalendarioMunicipal::CATALOGO => Http::response([], 503)]);

        $this->actingAs($jefa)->getJson(route('gestion.municipios', ['buscar' => 'Madrid']))->assertOk()
            ->assertJsonPath('municipios.0.ine', '28079')->assertJsonPath('hay_mas', true);

        $this->assertDatabaseCount('importaciones_calendario', 0);
        Http::assertNothingSent();
    }

    private function jefa(): User
    {
        $this->travelTo(Carbon::parse('2026-10-30 10:00:00', 'Europe/Madrid'));

        return User::factory()->create(['role' => 'jefa', 'centro_id' => Centro::query()->where('name', 'Madrid')->value('id')]);
    }

    private function fuentesMadrid(): void
    {
        Http::fake([
            CalendarioMunicipal::CATALOGO => Http::response($this->datos('municipios.json')),
            'festivos.io/v1/2026/municipio/28079.json' => Http::response($this->datos('madrid-2026.json')),
            'festivos.io/v1/2027/municipio/28079.json' => Http::response($this->datos('madrid-2027.json')),
            CalendarioMunicipal::CAM_REGIONALES => Http::response($this->datos('cam-regionales-2026.json')),
            CalendarioMunicipal::CAM_LOCALES => Http::response($this->datos('cam-locales-madrid-2026.json')),
            'https://www.boe.es/diario_boe/xml.php?id=BOE-A-2025-21667' => Http::response('', 503),
        ]);
    }

    private function datos(string $archivo): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/Calendario/'.$archivo)), true, flags: JSON_THROW_ON_ERROR);
    }
}
