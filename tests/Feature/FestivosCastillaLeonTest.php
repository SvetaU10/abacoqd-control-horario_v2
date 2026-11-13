<?php

namespace Tests\Feature;

use App\Services\FestivosCastillaLeon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FestivosCastillaLeonTest extends TestCase
{
    public function test_confirma_solo_las_fiestas_del_ano_y_municipio_de_la_respuesta_oficial_real(): void
    {
        $this->fuente($this->datos('jcyl-segovia.json'));

        $resultado = app(FestivosCastillaLeon::class)->consultar(2026, $this->segovia());

        $this->assertSame('confirmado', $resultado['estado']);
        $this->assertSame(['2026-06-29', '2026-10-26'], $resultado['fechas']);
        $this->assertSame(FestivosCastillaLeon::DATASET, $resultado['fuentes'][0]['url']);
        $this->assertStringContainsString('CC BY 4.0', $resultado['fuentes'][0]['nombre']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($peticion) => str_contains($peticion->url(), 'where=ine%3D40194') && str_contains($peticion->url(), 'offset=0'));
    }

    public function test_no_reutiliza_dos_fechas_del_ano_anterior_para_un_ano_sin_publicar(): void
    {
        $this->fuente($this->datos('jcyl-segovia.json'));

        $resultado = app(FestivosCastillaLeon::class)->consultar(2027, $this->segovia());

        $this->assertSame('pendiente_revision', $resultado['estado']);
        $this->assertSame([], $resultado['fechas']);
        Http::assertSentCount(1);
    }

    public function test_recupera_el_cero_inicial_del_ine_numerico_de_avila(): void
    {
        $this->fuente($this->datos('jcyl-avila.json'));
        $municipio = ['ine' => '05019', 'nombre' => 'Ávila', 'provincia' => 'Ávila', 'comunidad_codigo' => 'ES-CL'];

        $resultado = app(FestivosCastillaLeon::class)->consultar(2026, $municipio);

        $this->assertSame('confirmado', $resultado['estado']);
        $this->assertSame(['2026-05-02', '2026-10-15'], $resultado['fechas']);
        Http::assertSent(fn ($peticion) => str_contains($peticion->url(), 'where=ine%3D5019'));
    }

    public function test_no_mezcla_las_pedanias_del_mismo_ine_en_la_respuesta_real(): void
    {
        $this->fuente($this->datos('jcyl-valdeporres.json'));
        $municipio = ['ine' => '09216', 'nombre' => 'Merindad de Valdeporres', 'provincia' => 'Burgos', 'comunidad_codigo' => 'ES-CL'];

        $resultado = app(FestivosCastillaLeon::class)->consultar(2025, $municipio);

        $this->assertSame('pendiente_revision', $resultado['estado']);
        $this->assertSame([], $resultado['fechas']);
        $this->assertStringContainsString('entidades', $resultado['mensaje']);
        Http::assertSentCount(1);
    }

    public function test_una_fecha_oficial_en_blanco_no_se_inventa_ni_confirma_el_ano(): void
    {
        $this->fuente($this->datos('jcyl-osornillo.json'));
        $municipio = ['ine' => '34116', 'nombre' => 'Osornillo', 'provincia' => 'Palencia', 'comunidad_codigo' => 'ES-CL'];

        $resultado = app(FestivosCastillaLeon::class)->consultar(2026, $municipio);

        $this->assertSame('pendiente_revision', $resultado['estado']);
        $this->assertSame([], $resultado['fechas']);
        Http::assertSentCount(1);
    }

    public function test_una_provincia_incompatible_con_el_ine_se_rechaza_antes_de_consultar(): void
    {
        $municipio = $this->segovia();
        $municipio['provincia'] = 'Burgos';

        $resultado = app(FestivosCastillaLeon::class)->consultar(2026, $municipio);

        $this->assertSame('pendiente_revision', $resultado['estado']);
        $this->assertSame([], $resultado['fechas']);
        Http::assertNothingSent();
    }

    public function test_el_ine_no_puede_inyectar_una_consulta_en_la_api(): void
    {
        $municipio = $this->segovia();
        $municipio['ine'] = '40194 OR true';

        $resultado = app(FestivosCastillaLeon::class)->consultar(2026, $municipio);

        $this->assertSame('pendiente_revision', $resultado['estado']);
        Http::assertNothingSent();
    }

    public function test_dos_filas_de_otro_municipio_no_prueban_cobertura(): void
    {
        $datos = $this->dosFiestas();
        $datos['results'][0]['ine'] = 40195;
        $this->fuente($datos);

        $resultado = app(FestivosCastillaLeon::class)->consultar(2026, $this->segovia());

        $this->assertSame('conflicto', $resultado['estado']);
        $this->assertSame([], $resultado['fechas']);
        Http::assertSentCount(1);
    }

    public function test_dos_filas_con_provincia_erronea_no_prueban_cobertura(): void
    {
        $datos = $this->dosFiestas();
        $datos['results'][0]['provincia'] = 'BURGOS';
        $this->fuente($datos);

        $resultado = app(FestivosCastillaLeon::class)->consultar(2026, $this->segovia());

        $this->assertSame('conflicto', $resultado['estado']);
        $this->assertSame([], $resultado['fechas']);
        Http::assertSentCount(1);
    }

    public function test_dos_fechas_de_una_entidad_diferente_no_se_aplican_al_municipio(): void
    {
        $datos = $this->dosFiestas();
        $datos['results'][0]['municipio'] = 'ZAMARRAMALA (SEGOVIA)';
        $datos['results'][1]['municipio'] = 'ZAMARRAMALA (SEGOVIA)';
        $this->fuente($datos);

        $resultado = app(FestivosCastillaLeon::class)->consultar(2026, $this->segovia());

        $this->assertSame('pendiente_revision', $resultado['estado']);
        $this->assertSame([], $resultado['fechas']);
        Http::assertSentCount(1);
    }

    public function test_una_fecha_imposible_de_la_fuente_se_deja_para_revision(): void
    {
        $datos = $this->dosFiestas();
        $datos['results'][0]['fecha_fiesta'] = '2026-02-30';
        $this->fuente($datos);

        $resultado = app(FestivosCastillaLeon::class)->consultar(2026, $this->segovia());

        $this->assertSame('pendiente_revision', $resultado['estado']);
        $this->assertSame([], $resultado['fechas']);
        Http::assertSentCount(1);
    }

    public function test_una_fiesta_provisional_no_se_confirma_aunque_ya_tenga_fecha(): void
    {
        $datos = $this->dosFiestas();
        $datos['results'][0]['nombre_fiesta'] = 'PENDIENTE DE COMUNICAR';
        $this->fuente($datos);

        $resultado = app(FestivosCastillaLeon::class)->consultar(2026, $this->segovia());

        $this->assertSame('pendiente_revision', $resultado['estado']);
        $this->assertSame(['2026-10-26'], $resultado['fechas']);
        Http::assertSentCount(1);
    }

    public function test_no_elije_entre_una_fecha_antigua_y_su_posible_correccion(): void
    {
        $datos = $this->dosFiestas();
        $correccion = $datos['results'][0];
        $correccion['fecha_fiesta'] = '2026-06-30';
        $datos['results'][] = $correccion;
        $datos['total_count'] = 3;
        $this->fuente($datos);

        $resultado = app(FestivosCastillaLeon::class)->consultar(2026, $this->segovia());

        $this->assertSame('conflicto', $resultado['estado']);
        $this->assertSame([], $resultado['fechas']);
        Http::assertSentCount(1);
    }

    public function test_una_actualizacion_reconsulta_la_fuente_y_recoge_la_correccion_vigente(): void
    {
        $original = $this->dosFiestas();
        $corregido = $original;
        $corregido['results'][0]['fecha_fiesta'] = '2026-06-30';
        Http::fake([FestivosCastillaLeon::API.'*' => Http::sequence()->push($original)->push($corregido)]);
        $servicio = app(FestivosCastillaLeon::class);

        $primera = $servicio->consultar(2026, $this->segovia());
        $segunda = $servicio->consultar(2026, $this->segovia());

        $this->assertSame(['2026-06-29', '2026-10-26'], $primera['fechas']);
        $this->assertSame('confirmado', $segunda['estado']);
        $this->assertSame(['2026-06-30', '2026-10-26'], $segunda['fechas']);
        Http::assertSentCount(2);
    }

    public function test_los_duplicados_exactos_no_crean_una_tercera_fiesta(): void
    {
        $datos = $this->dosFiestas();
        $datos['results'][] = $datos['results'][0];
        $datos['total_count'] = 3;
        $this->fuente($datos);

        $resultado = app(FestivosCastillaLeon::class)->consultar(2026, $this->segovia());

        $this->assertSame('confirmado', $resultado['estado']);
        $this->assertSame(['2026-06-29', '2026-10-26'], $resultado['fechas']);
        Http::assertSentCount(1);
    }

    public function test_recupera_todas_las_paginas_antes_de_decidir_la_cobertura(): void
    {
        $antigua = $this->datos('jcyl-segovia.json')['results'][0];
        $actuales = $this->dosFiestas()['results'];
        Http::fake([FestivosCastillaLeon::API.'*' => Http::sequence()
            ->push(['total_count' => 102, 'results' => array_fill(0, 100, $antigua)])
            ->push(['total_count' => 102, 'results' => $actuales])]);

        $resultado = app(FestivosCastillaLeon::class)->consultar(2026, $this->segovia());

        $this->assertSame('confirmado', $resultado['estado']);
        $this->assertSame(['2026-06-29', '2026-10-26'], $resultado['fechas']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($peticion) => str_contains($peticion->url(), 'offset=100'));
    }

    public function test_una_pagina_truncada_no_se_confunde_con_un_calendario_completo(): void
    {
        $datos = $this->dosFiestas();
        $datos['total_count'] = 3;
        $this->fuente($datos);

        $resultado = app(FestivosCastillaLeon::class)->consultar(2026, $this->segovia());

        $this->assertSame('pendiente_revision', $resultado['estado']);
        $this->assertSame([], $resultado['fechas']);
        Http::assertSentCount(1);
    }

    public function test_un_cambio_de_total_entre_paginas_impide_la_confirmacion(): void
    {
        $antigua = $this->datos('jcyl-segovia.json')['results'][0];
        Http::fake([FestivosCastillaLeon::API.'*' => Http::sequence()
            ->push(['total_count' => 102, 'results' => array_fill(0, 100, $antigua)])
            ->push(['total_count' => 101, 'results' => $this->dosFiestas()['results']])]);

        $resultado = app(FestivosCastillaLeon::class)->consultar(2026, $this->segovia());

        $this->assertSame('pendiente_revision', $resultado['estado']);
        $this->assertSame([], $resultado['fechas']);
        Http::assertSentCount(2);
    }

    public function test_un_error_http_oficial_deja_el_calendario_pendiente(): void
    {
        Http::fake([FestivosCastillaLeon::API.'*' => Http::response([], 503)]);

        $resultado = app(FestivosCastillaLeon::class)->consultar(2026, $this->segovia());

        $this->assertSame('pendiente_revision', $resultado['estado']);
        $this->assertSame([], $resultado['fechas']);
        $this->assertStringContainsString('HTTP 503', $resultado['mensaje']);
        Http::assertSentCount(1);
    }

    public function test_una_caida_de_conexion_no_se_convierte_en_fiestas_confirmadas(): void
    {
        Http::fake([FestivosCastillaLeon::API.'*' => Http::failedConnection()]);

        $resultado = app(FestivosCastillaLeon::class)->consultar(2026, $this->segovia());

        $this->assertSame('pendiente_revision', $resultado['estado']);
        $this->assertSame([], $resultado['fechas']);
        $this->assertCount(2, $resultado['fuentes']);
        Http::assertSentCount(1);
    }

    public function test_una_respuesta_ajena_al_formato_oficial_no_se_confirma(): void
    {
        Http::fake([FestivosCastillaLeon::API.'*' => Http::response('<html>No disponible</html>')]);

        $resultado = app(FestivosCastillaLeon::class)->consultar(2026, $this->segovia());

        $this->assertSame('pendiente_revision', $resultado['estado']);
        $this->assertSame([], $resultado['fechas']);
        Http::assertSentCount(1);
    }

    private function segovia(): array
    {
        return ['ine' => '40194', 'nombre' => 'Segovia', 'provincia' => 'Segovia', 'comunidad_codigo' => 'ES-CL'];
    }

    private function fuente(array $datos): void
    {
        Http::fake([FestivosCastillaLeon::API.'*' => Http::response($datos)]);
    }

    private function datos(string $archivo): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/Calendario/'.$archivo)), true, flags: JSON_THROW_ON_ERROR);
    }

    private function dosFiestas(): array
    {
        return ['total_count' => 2, 'results' => array_slice($this->datos('jcyl-segovia.json')['results'], -2)];
    }
}
