<?php

namespace Tests\Feature;

use App\Models\ImportacionCalendario;
use App\Models\User;
use App\Services\CalendarioMunicipal;
use App\Services\VigenciasCalendario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CalendarioVigenciaHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_pantalla_ofrece_ano_entero_o_fecha_de_efecto_y_leyenda_visible(): void
    {
        $jefa = $this->jefa();

        $this->actingAs($jefa)->get(route('gestion.calendario.mostrar', ['centro' => $jefa->centro_id, 'anio' => 2026]))
            ->assertOk()->assertViewHas('modoVigencia', 'anio')
            ->assertSee('name="vigente_desde"', false)->assertSee('name="vigencia_modo"', false)
            ->assertSee('Todo el año, desde el 1 de enero')->assertSee('Desde una fecha')
            ->assertSee('Provincial/insular')->assertSee('cuando exista')->assertSee('Municipal');

        $this->calendarioConfirmado($jefa->sede, 2026);
        $this->get(route('gestion.calendario.mostrar', ['centro' => $jefa->centro_id, 'anio' => 2026]))
            ->assertOk()->assertViewHas('modoVigencia', 'fecha')->assertViewHas('fechaVigencia', '2026-10-30');
        Http::assertNothingSent();
    }

    public function test_la_fecha_debe_existir_y_pertenecer_al_ano_sin_consultar_fuentes(): void
    {
        $jefa = $this->jefa();
        foreach (['', '2025-12-31', '2027-01-01', '2026-02-30'] as $fecha) {
            $this->actingAs($jefa)->post(route('gestion.calendario.cargar', $jefa->sede), [
                'anio' => 2026, 'municipio_ine' => '28079', 'vigencia_modo' => 'fecha', 'vigente_desde' => $fecha,
            ])->assertSessionHasErrors('vigente_desde');
        }

        $this->assertDatabaseCount('importaciones_calendario', 0);
        Http::assertNothingSent();
    }

    public function test_ano_entero_fija_uno_de_enero_aunque_el_navegador_envie_otra_fecha(): void
    {
        $jefa = $this->jefa();
        $this->fuentes();

        $this->actingAs($jefa)->post(route('gestion.calendario.cargar', $jefa->sede), [
            'anio' => 2026, 'municipio_ine' => '28079', 'vigencia_modo' => 'anio', 'vigente_desde' => '2026-06-01',
        ])->assertSessionHasNoErrors()->assertSessionHas('ok');

        $this->assertSame('2026-01-01', ImportacionCalendario::query()->whereNotNull('clave_activa')->sole()->vigente_desde->toDateString());
    }

    public function test_corregir_una_fecha_no_cambia_el_municipio_ni_el_ano_que_se_habian_elegido(): void
    {
        $jefa = $this->jefa();
        $pantalla = route('gestion.calendario.mostrar', ['centro' => $jefa->sede, 'anio' => 2026]);
        $this->actingAs($jefa)->from($pantalla)->post(route('gestion.calendario.cargar', $jefa->sede), [
            'anio' => 2027, 'municipio_ine' => '40194', 'vigencia_modo' => 'fecha', 'vigente_desde' => '2026-02-01',
        ])->assertSessionHasErrors('vigente_desde');

        $this->get($pantalla)->assertOk()->assertViewHas('anio', 2027)
            ->assertViewHas('municipioElegido', fn (array $municipio): bool => $municipio['ine'] === '40194')
            ->assertViewHas('modoVigencia', 'fecha');
        Http::assertNothingSent();
    }

    public function test_confirmar_segovia_desde_febrero_conserva_madrid_en_enero_y_la_fecha_guardada(): void
    {
        $jefa = $this->jefa();
        $this->fuentes();
        $this->actingAs($jefa)->post(route('gestion.calendario.cargar', $jefa->sede), [
            'anio' => 2026, 'municipio_ine' => '28079', 'vigencia_modo' => 'anio',
        ])->assertSessionHas('ok');
        $this->post(route('gestion.calendario.cargar', $jefa->sede), [
            'anio' => 2026, 'municipio_ine' => '40194', 'vigencia_modo' => 'fecha', 'vigente_desde' => '2026-02-01',
        ])->assertSessionHasNoErrors()->assertSessionHas('aviso');
        $candidato = ImportacionCalendario::query()->latest('id')->firstOrFail();
        $this->assertSame('2026-02-01', $candidato->vigente_desde->toDateString());
        $this->get(route('gestion.calendario.mostrar', ['centro' => $jefa->centro_id, 'anio' => 2026]))
            ->assertOk()->assertSee('se aplicará desde el 01/02/2026');

        $this->post(route('gestion.calendario.confirmar', ['centro' => $jefa->sede, 'importacion' => $candidato]), [
            'revision' => '1', 'vigente_desde' => '2026-01-01',
        ])->assertSessionHas('ok');

        $tramos = app(VigenciasCalendario::class)->delAnio($jefa->sede, 2026);
        $this->assertCount(2, $tramos);
        $this->assertSame('Madrid', $tramos[0]->importacion->municipio);
        $this->assertSame('2026-01-31', $tramos[0]->hasta->toDateString());
        $this->assertSame('Segovia', $tramos[1]->importacion->municipio);
        $this->assertSame('2026-02-01', $tramos[1]->desde->toDateString());
        $this->get(route('gestion.calendario.mostrar', ['centro' => $jefa->sede, 'anio' => 2026]))
            ->assertOk()->assertSee('01/01–31/01')->assertSee('01/02–31/12')->assertSee('Madrid')->assertSee('Segovia');
    }

    public function test_mes_y_ano_muestran_ambitos_y_vacaciones_con_texto_y_municipio_por_periodo(): void
    {
        $jefa = $this->jefa();
        $this->importarFixture($jefa, 'Madrid', '2026-01-01', [
            ['date' => '2026-01-01', 'name' => 'Nacional enero', 'ambito' => 'estado'],
            ['date' => '2026-01-02', 'name' => 'Autonómico enero', 'ambito' => 'comunidad'],
            ['date' => '2026-01-03', 'name' => 'Municipal Madrid enero', 'ambito' => 'pueblo'],
            ['date' => '2026-01-04', 'name' => 'Provincial enero', 'ambito' => 'provincia'],
            ['date' => '2026-01-05', 'name' => 'Insular enero', 'ambito' => 'insular'],
            ['date' => '2026-02-02', 'name' => 'Madrid fuera de vigencia', 'ambito' => 'pueblo'],
        ]);
        $this->importarFixture($jefa, 'Segovia', '2026-02-01', [
            ['date' => '2026-01-08', 'name' => 'Segovia antes de vigencia', 'ambito' => 'pueblo'],
            ['date' => '2026-02-10', 'name' => 'Municipal Segovia febrero', 'ambito' => 'pueblo'],
        ]);
        $jefa->ausencias()->create(['absence_date' => '2026-01-12', 'tipo' => 'vacaciones']);
        $jefa->ausencias()->create(['absence_date' => '2026-01-01', 'tipo' => 'vacaciones']);

        $respuestaMes = $this->actingAs($jefa)->get(route('calendario', ['anio' => 2026, 'mes' => 1]))->assertOk()
            ->assertSee('Municipal Madrid enero')->assertDontSee('Segovia antes de vigencia')
            ->assertSee('data-ambito="estado"', false)->assertSee('data-ambito="comunidad"', false)
            ->assertSee('data-ambito="pueblo"', false)->assertSee('data-ambito="provincia"', false)->assertSee('data-ambito="insular"', false)
            ->assertSee('data-relleno="vacaciones"', false)->assertSee('Nacional enero · Festivo Nacional', false)
            ->assertSee('cal-leyenda', false)->assertSee('Provincial/insular')->assertSee('Vacaciones');
        $documento = new \DOMDocument;
        $documento->loadHTML('<?xml encoding="UTF-8">'.$respuestaMes->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($documento);
        $cruce = $xpath->query('//*[@data-fecha="2026-01-01"]')->item(0);
        $this->assertSame('vacaciones', $cruce->getAttribute('data-relleno'));
        $this->assertSame('estado', $cruce->getAttribute('data-ambito'));
        $this->assertSame('0', $cruce->getAttribute('data-cuenta'));
        $this->assertStringContainsString('Festivo Nacional', $cruce->getAttribute('title'));
        $this->assertStringContainsString('Vacaciones', $cruce->textContent);
        $this->assertSame(0, $xpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," festivo-ambito ")]', $cruce)->length);
        $this->get(route('calendario', ['anio' => 2026, 'mes' => 2]))->assertOk()
            ->assertSee('Municipal Segovia febrero')->assertDontSee('Madrid fuera de vigencia');
        $this->get(route('calendario', ['anio' => 2026, 'vista' => 'anio']))->assertOk()
            ->assertSee('cal-leyenda', false)
            ->assertSee('data-ambito="provincia"', false)->assertSee('data-ambito="insular"', false)
            ->assertSee('Municipal Madrid enero')->assertSee('Municipal Segovia febrero')
            ->assertDontSee('Madrid fuera de vigencia')->assertDontSee('Segovia antes de vigencia');
        Http::assertNothingSent();
    }

    public function test_un_error_de_la_fuente_muestra_un_resumen_simple_y_conserva_el_detalle_original(): void
    {
        $jefa = $this->jefa();
        Http::fake(['https://festivos.io/v1/2026/municipio/28079.json' => Http::response([], 503)]);
        $resumen = 'No se ha podido consultar el calendario. Tus datos se conservan. Puedes intentarlo más tarde.';

        $this->actingAs($jefa)->post(route('gestion.calendario.cargar', $jefa->sede), [
            'anio' => 2026, 'municipio_ine' => '28079', 'vigencia_modo' => 'anio',
        ])->assertSessionHas('aviso', $resumen);

        $consulta = ImportacionCalendario::query()->sole();
        $this->assertStringContainsString('HTTP 503', $consulta->mensaje);
        $respuesta = $this->get(route('gestion.calendario.mostrar', ['centro' => $jefa->sede, 'anio' => 2026]))
            ->assertOk()->assertViewHas('resumenConsulta', $resumen)->assertSee('Detalles de la consulta');
        $this->assertStringContainsString($consulta->mensaje, html_entity_decode($respuesta->getContent(), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $documento = new \DOMDocument;
        $documento->loadHTML('<?xml encoding="UTF-8">'.$respuesta->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($documento);
        $detalle = $xpath->query('//details[@data-consulta-detalle]')->item(0);
        $this->assertNotNull($detalle);
        $this->assertFalse($detalle->hasAttribute('open'));
        $this->assertStringContainsString('HTTP 503', $detalle->textContent);
        foreach ($xpath->query('//main//p[not(ancestor::details)]') as $parrafo) {
            $this->assertStringNotContainsString('HTTP 503', $parrafo->textContent);
        }
    }

    public function test_un_valor_de_formulario_invalido_permite_volver_y_corregirlo(): void
    {
        $jefa = $this->jefa();
        $pantalla = route('gestion.calendario.mostrar', ['centro' => $jefa->sede, 'anio' => 2026]);
        $this->actingAs($jefa)->from($pantalla)->post(route('gestion.calendario.cargar', $jefa->sede), [
            'anio' => 2026, 'municipio_ine' => '28079', 'vigencia_modo' => ['valor no válido'], 'vigente_desde' => ['fecha no válida'],
        ])->assertSessionHasErrors(['vigencia_modo', 'vigente_desde']);

        $this->get($pantalla)->assertOk()->assertViewHas('modoVigencia', 'anio')->assertViewHas('fechaVigencia', '2026-10-30');
        $this->assertDatabaseCount('importaciones_calendario', 0);
        Http::assertNothingSent();
    }

    /** @param list<array<string,string>> $dias */
    private function importarFixture(User $autor, string $municipio, string $desde, array $dias): void
    {
        $importacion = ImportacionCalendario::create([
            'centro_id' => $autor->centro_id, 'anio' => 2026, 'municipio_ine' => $municipio === 'Madrid' ? '28079' : '40194',
            'municipio' => $municipio, 'provincia' => $municipio, 'comunidad' => 'Comunidad de prueba', 'comunidad_codigo' => 'ES-MD',
            'estado' => 'cargado', 'mensaje' => 'Calendario confirmado de prueba', 'festivos' => $dias, 'fuentes' => [],
            'vigente_desde' => $desde, 'consultado_at' => now(), 'activado_at' => now(),
        ]);
        app(VigenciasCalendario::class)->activar($importacion);
    }

    private function jefa(): User
    {
        $this->travelTo(Carbon::parse('2026-10-30 10:00:00', 'Europe/Madrid'));

        return User::factory()->jefa()->create();
    }

    private function fuentes(): void
    {
        $datos = fn (string $nombre): array => json_decode(file_get_contents(base_path('tests/Fixtures/Calendario/'.$nombre.'.json')), true, flags: JSON_THROW_ON_ERROR);
        Http::fake([
            'https://festivos.io/v1/2026/municipio/28079.json' => Http::response($datos('madrid-2026')),
            'https://festivos.io/v1/2026/municipio/40194.json' => Http::response($datos('segovia-2026')),
            CalendarioMunicipal::CAM_REGIONALES => Http::response($datos('cam-regionales-2026')),
            CalendarioMunicipal::CAM_LOCALES => Http::response($datos('cam-locales-madrid-2026')),
            'https://www.boe.es/diario_boe/xml.php?id=BOE-A-2025-21667' => Http::response('', 503),
        ]);
    }
}
