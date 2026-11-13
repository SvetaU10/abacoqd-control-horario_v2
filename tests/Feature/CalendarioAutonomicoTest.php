<?php

namespace Tests\Feature;

use App\Models\Centro;
use App\Models\ImportacionCalendario;
use App\Models\User;
use App\Services\CalendarioMunicipal;
use App\Services\FestivosCastillaLeon;
use App\Services\FestivosValencia;
use App\Services\NagerFestivos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CalendarioAutonomicoTest extends TestCase
{
    use RefreshDatabase;

    public function test_segovia_se_activa_sin_confirmacion_manual_tras_comprobar_boe_y_junta(): void
    {
        $this->fuentes();
        $jefa = $this->jefa();

        $this->actingAs($jefa)->post(route('gestion.calendario.cargar', $jefa->centro_id), ['anio' => 2026, 'municipio_ine' => '40194'])
            ->assertRedirect()->assertSessionHas('ok');

        $activo = ImportacionCalendario::query()->whereNotNull('clave_activa')->sole();
        $this->assertSame('cargado', $activo->estado);
        $this->assertCount(14, $activo->festivos);
        $this->assertTrue(app(NagerFestivos::class)->es($jefa->sede, Carbon::parse('2026-10-26')));
        $this->assertStringContainsString('BOE', $activo->mensaje);
        $this->assertContains(FestivosCastillaLeon::DATASET, array_column($activo->fuentes, 'url'));
        Http::assertSentCount(3);
    }

    public function test_actualizar_segovia_sin_cambios_no_duplica_la_version_activa(): void
    {
        $this->fuentes();
        $jefa = $this->jefa();
        $servicio = app(CalendarioMunicipal::class);
        $primera = $servicio->cargar($jefa->sede, 2026, '40194', $jefa);

        $resultado = $servicio->cargar($jefa->sede, 2026, '40194', $jefa);

        $this->assertSame('sin_cambios', $resultado->estado);
        $this->assertSame($primera->id, $servicio->activo($jefa->sede, 2026)->id);
        $this->assertSame(1, ImportacionCalendario::query()->whereNotNull('clave_activa')->count());
        Http::assertSentCount(6);
    }

    public function test_valencia_se_activa_automaticamente_y_excluye_cierres_de_oficinas(): void
    {
        $this->fuentesValencia();
        $this->travelTo(Carbon::parse('2026-10-30 12:00:00', 'Europe/Madrid'));
        $centro = Centro::create(['name' => 'València', 'municipality' => 'València', 'community' => 'Comunitat Valenciana', 'municipio_ine' => '46250']);
        $jefa = User::factory()->jefa()->create(['centro_id' => $centro->id]);

        $this->actingAs($jefa)->post(route('gestion.calendario.cargar', $centro), ['anio' => 2026, 'municipio_ine' => '46250'])
            ->assertRedirect()->assertSessionHas('ok');

        $activo = ImportacionCalendario::query()->whereNotNull('clave_activa')->sole();
        $this->assertSame('cargado', $activo->estado);
        $this->assertCount(14, $activo->festivos);
        $this->assertSame(['2026-01-22', '2026-04-13'], array_values(array_column(array_filter($activo->festivos, fn (array $dia): bool => $dia['ambito'] === 'pueblo'), 'date')));
        $this->assertTrue(app(NagerFestivos::class)->es($centro, Carbon::parse('2026-04-13')));
        $this->assertFalse(app(NagerFestivos::class)->es($centro, Carbon::parse('2026-12-24')));
        $this->assertFalse(app(NagerFestivos::class)->es($centro, Carbon::parse('2026-12-31')));
        Http::assertSentCount(11);
    }

    public function test_una_fecha_nacional_distinta_del_boe_conserva_el_calendario_anterior(): void
    {
        $alterado = $this->datos('segovia-2026.json');
        $alterado['holidays'][0]['date'] = '2026-01-02';
        $this->fuentes(['https://festivos.io/v1/2026/municipio/40194.json' => Http::sequence()->push($this->datos('segovia-2026.json'))->push($alterado)]);
        $jefa = $this->jefa();
        $servicio = app(CalendarioMunicipal::class);
        $primera = $servicio->cargar($jefa->sede, 2026, '40194', $jefa);

        $resultado = $servicio->cargar($jefa->sede, 2026, '40194', $jefa);

        $this->assertSame('conflicto', $resultado->estado);
        $this->assertFalse($resultado->estaActivo());
        $this->assertSame($primera->id, $servicio->activo($jefa->sede, 2026)->id);
        Http::assertSentCount(6);
    }

    public function test_una_fecha_local_distinta_de_la_junta_no_confirma_la_cobertura(): void
    {
        $alterado = $this->datos('segovia-2026.json');
        foreach ($alterado['holidays'] as &$dia) {
            if ($dia['level'] === 'local' && $dia['date'] === '2026-06-29') {
                $dia['date'] = '2026-06-30';
            }
        }
        unset($dia);
        $this->fuentes(['https://festivos.io/v1/2026/municipio/40194.json' => Http::response($alterado)]);
        $jefa = $this->jefa();

        $resultado = app(CalendarioMunicipal::class)->cargar($jefa->sede, 2026, '40194', $jefa);

        $this->assertSame('conflicto', $resultado->estado);
        $this->assertFalse(app(NagerFestivos::class)->coberturaConfirmada($jefa->sede, 2026));
        Http::assertSentCount(3);
    }

    public function test_una_clasificacion_nacional_autonomica_incorrecta_requiere_revision(): void
    {
        $alterado = $this->datos('segovia-2026.json');
        $alterado['holidays'][0]['level'] = 'regional';
        $this->fuentes(['https://festivos.io/v1/2026/municipio/40194.json' => Http::response($alterado)]);
        $jefa = $this->jefa();

        $resultado = app(CalendarioMunicipal::class)->cargar($jefa->sede, 2026, '40194', $jefa);

        $this->assertSame('conflicto', $resultado->estado);
        $this->assertFalse($resultado->estaActivo());
        Http::assertSentCount(3);
    }

    public function test_el_boe_caido_conserva_la_version_oficial_previa_y_no_da_sin_cambios(): void
    {
        $this->fuentes(['https://www.boe.es/diario_boe/xml.php?id=BOE-A-2025-21667' => Http::sequence()->push(file_get_contents(base_path('tests/Fixtures/Calendario/boe-2026.xml')))->pushStatus(503)]);
        $jefa = $this->jefa();
        $servicio = app(CalendarioMunicipal::class);
        $primera = $servicio->cargar($jefa->sede, 2026, '40194', $jefa);

        $resultado = $servicio->cargar($jefa->sede, 2026, '40194', $jefa);

        $this->assertSame('pendiente_revision', $resultado->estado);
        $this->assertSame($primera->id, $servicio->activo($jefa->sede, 2026)->id);
        Http::assertSentCount(5);
    }

    public function test_una_fuente_local_sin_datos_del_ano_solicitado_no_confirma_otro_ano(): void
    {
        $this->fuentes([FestivosCastillaLeon::API.'*' => Http::response(['total_count' => 0, 'results' => []])]);
        $jefa = $this->jefa();

        $resultado = app(CalendarioMunicipal::class)->cargar($jefa->sede, 2026, '40194', $jefa);

        $this->assertSame('pendiente_revision', $resultado->estado);
        $this->assertSame(0, ImportacionCalendario::query()->whereNotNull('clave_activa')->count());
        Http::assertSentCount(3);
    }

    private function jefa(): User
    {
        $this->travelTo(Carbon::parse('2026-10-30 12:00:00', 'Europe/Madrid'));
        $centro = Centro::create(['name' => 'Segovia', 'municipality' => 'Segovia', 'community' => 'Castilla y León']);

        return User::factory()->jefa()->create(['centro_id' => $centro->id]);
    }

    private function fuentes(array $cambios = []): void
    {
        Http::fake(array_replace([
            'https://festivos.io/v1/2026/municipio/40194.json' => Http::response($this->datos('segovia-2026.json')),
            'https://www.boe.es/diario_boe/xml.php?id=BOE-A-2025-21667' => Http::response(file_get_contents(base_path('tests/Fixtures/Calendario/boe-2026.xml'))),
            FestivosCastillaLeon::API.'*' => Http::response($this->datos('jcyl-segovia.json')),
        ], $cambios));
    }

    private function datos(string $archivo): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/Calendario/'.$archivo)), true, flags: JSON_THROW_ON_ERROR);
    }

    private function fuentesValencia(): void
    {
        Http::fake([
            'https://festivos.io/v1/2026/municipio/46250.json' => Http::response($this->datos('valencia-2026.json')),
            'https://www.boe.es/diario_boe/xml.php?id=BOE-A-2025-21667' => Http::response(file_get_contents(base_path('tests/Fixtures/Calendario/boe-2026.xml'))),
            FestivosValencia::INDICE => Http::response(file_get_contents(base_path('tests/Fixtures/Calendario/gva-indice-2026-10-30.html'))),
            FestivosValencia::MUNICIPAL => Http::response(file_get_contents(base_path('tests/Fixtures/Calendario/gva-valencia-municipal-2026.html'))),
            FestivosValencia::API.'/dogv/obtenerIdDogv/2025-46326' => Http::response('454613'),
            FestivosValencia::API.'/dogv/obtenerIdDogv/2026-1043' => Http::response('462310'),
            FestivosValencia::API.'/dogv/obtenerIdDogv/2026-6733' => Http::response('468020'),
            FestivosValencia::API.'/disposicion/454613?lang=es_es' => Http::response($this->datos('gva-dogv-454613.json')),
            FestivosValencia::API.'/disposicion/462310?lang=es_es' => Http::response($this->datos('gva-dogv-462310.json')),
            FestivosValencia::API.'/disposicion/468020?lang=es_es' => Http::response($this->datos('gva-dogv-468020.json')),
            FestivosValencia::API.'/disposicion/454613/correccionErrores?lang=es_es' => Http::response($this->datos('gva-dogv-454613-correcciones.json')),
        ]);
    }
}
