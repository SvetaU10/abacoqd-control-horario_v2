<?php

namespace Tests\Feature;

use App\Models\Centro;
use App\Models\ImportacionCalendario;
use App\Models\User;
use App\Services\CalendarioMunicipal;
use App\Services\CatalogoMunicipios;
use App\Services\FestivosEspana;
use App\Services\VigenciasCalendario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CalendarioNacionalVigenciaTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('municipiosGenericos')]
    public function test_el_boton_carga_otras_comunidades_y_municipios_sin_lista_de_permitidos(string $ine): void
    {
        $this->fuentes($ine);
        $jefa = $this->jefa($ine);

        $this->actingAs($jefa)->post(route('gestion.calendario.cargar', $jefa->centro_id), ['anio' => 2026, 'municipio_ine' => $ine])
            ->assertRedirect()->assertSessionHas('ok');

        $activo = ImportacionCalendario::query()->whereNotNull('clave_activa')->sole();
        $this->assertSame($ine, $activo->municipio_ine);
        $this->assertStringContainsString('concordancia entre proveedores', $activo->mensaje);
        $this->assertSame('2026-01-01', $activo->vigente_desde->toDateString());
        $this->assertCount(1, app(VigenciasCalendario::class)->delAnio($jefa->sede, 2026));
        Http::assertSentCount(2);
    }

    public static function municipiosGenericos(): array
    {
        return ['Barcelona' => ['08019'], 'Sevilla' => ['41091'], 'Cantillana' => ['41023'], 'Formentera' => ['07024'], 'Palma' => ['07040']];
    }

    public function test_el_mismo_contenido_con_otra_fecha_de_efecto_modifica_la_vigencia(): void
    {
        $this->fuentes('08019');
        $jefa = $this->jefa('08019');
        $servicio = app(CalendarioMunicipal::class);
        $primera = $servicio->cargar($jefa->sede, 2026, '08019', $jefa, desde: '2026-02-01');

        $resultado = $servicio->cargar($jefa->sede, 2026, '08019', $jefa, desde: '2026-01-01');

        $this->assertSame('actualizado', $resultado->estado);
        $this->assertNotSame($primera->id, $resultado->id);
        $this->assertSame($primera->hash, $resultado->hash);
        $this->assertSame('2026-01-01', app(VigenciasCalendario::class)->delAnio($jefa->sede, 2026)->sole()->desde->toDateString());
        Http::assertSentCount(3);
    }

    public function test_la_revision_automatica_conserva_la_fecha_de_efecto_elegida(): void
    {
        $this->fuentes('08019');
        $jefa = $this->jefa('08019');
        app(CalendarioMunicipal::class)->cargar($jefa->sede, 2026, '08019', $jefa, desde: '2026-02-01');

        $this->artisan('calendario:revisar', ['--autor' => $jefa->id, '--anio' => 2026, '--centro' => $jefa->centro_id])->assertSuccessful();

        $this->assertSame('2026-02-01', app(CalendarioMunicipal::class)->ultima($jefa->sede, 2026)->vigente_desde->toDateString());
        $this->assertNull(app(VigenciasCalendario::class)->paraFecha($jefa->sede, Carbon::parse('2026-01-22')));
        $this->assertSame('2026-02-01', app(VigenciasCalendario::class)->delAnio($jefa->sede, 2026)->sole()->desde->toDateString());
        Http::assertSentCount(3);
    }

    public function test_reconsultar_igual_desde_una_fecha_posterior_no_fragmenta_el_historial(): void
    {
        $this->fuentes('08019');
        $jefa = $this->jefa('08019');
        $servicio = app(CalendarioMunicipal::class);
        $primera = $servicio->cargar($jefa->sede, 2026, '08019', $jefa, desde: '2026-02-01');

        $resultado = $servicio->cargar($jefa->sede, 2026, '08019', $jefa, desde: '2026-10-30');

        $this->assertSame('sin_cambios', $resultado->estado);
        $this->assertSame($primera->id, $servicio->activo($jefa->sede, 2026)->id);
        $this->assertSame('2026-02-01', app(VigenciasCalendario::class)->delAnio($jefa->sede, 2026)->sole()->desde->toDateString());
        Http::assertSentCount(3);
    }

    private function jefa(string $ine): User
    {
        $this->travelTo(Carbon::parse('2026-10-30 12:00:00', 'Europe/Madrid'));
        $municipio = app(CatalogoMunicipios::class)->resolver($ine);
        $centro = Centro::create(['name' => 'Centro '.$ine, 'municipality' => $municipio['nombre'], 'community' => $municipio['comunidad'], 'municipio_ine' => $ine]);

        return User::factory()->jefa()->create(['centro_id' => $centro->id]);
    }

    private function fuentes(string $ine): void
    {
        Http::fake([
            'https://festivos.io/v1/2026/municipio/'.$ine.'.json' => Http::response($this->datos('festivosio-'.$ine.'-2026.json')),
            FestivosEspana::API.'*' => Http::response($this->datos('espana-'.$ine.'-2026.json')),
        ]);
    }

    private function datos(string $archivo): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/Calendario/'.$archivo)), true, flags: JSON_THROW_ON_ERROR);
    }
}
