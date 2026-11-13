<?php

namespace Tests\Feature;

use App\Services\FestivosValencia;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class FestivosValenciaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-30 12:00:00', 'Europe/Madrid'));
        Cache::flush();
    }

    public function test_valencia_confirma_solo_sus_dos_fiestas_locales_y_excluye_cierres_funcionariales(): void
    {
        $this->fuentes();

        $resultado = app(FestivosValencia::class)->consultar(2026, $this->municipio());

        $this->assertSame('confirmado', $resultado['estado']);
        $this->assertSame(['2026-01-22', '2026-04-13'], $resultado['fechas']);
        $this->assertNotContains('2026-03-18', $resultado['fechas']);
        $this->assertNotContains('2026-12-24', $resultado['fechas']);
        $this->assertContains(FestivosValencia::MUNICIPAL, array_column($resultado['fuentes'], 'url'));
        $this->assertCount(5, $resultado['fuentes']);
        Http::assertSentCount(9);
    }

    public function test_un_ano_sin_resolucion_local_no_reutiliza_las_fiestas_del_anterior(): void
    {
        $this->fuentes();

        $resultado = app(FestivosValencia::class)->consultar(2027, $this->municipio());

        $this->assertSame('pendiente_revision', $resultado['estado']);
        $this->assertSame([], $resultado['fechas']);
        $this->assertStringContainsString('año anterior', $resultado['mensaje']);
        Http::assertSentCount(1);
    }

    public function test_un_corte_transitorio_se_reintenta_una_vez_y_permite_validar(): void
    {
        Sleep::fake();
        $this->fuentes([FestivosValencia::INDICE => Http::sequence()->pushFailedConnection('corte transitorio')->push($this->fixture('gva-indice-2026-10-30.html'))]);

        $resultado = app(FestivosValencia::class)->consultar(2026, $this->municipio());

        $this->assertSame('confirmado', $resultado['estado']);
        $this->assertSame(['2026-01-22', '2026-04-13'], $resultado['fechas']);
        Http::assertSentCount(10);
    }

    public function test_orba_aplica_la_rectificacion_publicada_en_marzo_y_no_sus_fechas_antiguas(): void
    {
        $this->fuentes();

        $resultado = app(FestivosValencia::class)->consultar(2026, $this->municipio('03097', 'Orba', 'Alicante/Alacant'));

        $this->assertSame('confirmado', $resultado['estado']);
        $this->assertSame(['2026-04-13', '2026-05-11'], $resultado['fechas']);
        $this->assertNotContains('2026-12-26', $resultado['fechas']);
        Http::assertSentCount(8);
        Http::assertNotSent(fn ($peticion) => $peticion->url() === FestivosValencia::MUNICIPAL);
    }

    public function test_benissoda_aplica_la_rectificacion_de_enero_por_nombre_exacto_y_provincia(): void
    {
        $this->fuentes();

        $resultado = app(FestivosValencia::class)->consultar(2026, $this->municipio('46068', 'Benissoda', 'Valencia/València'));

        $this->assertSame('confirmado', $resultado['estado']);
        $this->assertSame(['2026-03-20', '2026-04-13'], $resultado['fechas']);
        $this->assertNotContains('2026-04-20', $resultado['fechas']);
        Http::assertSentCount(8);
    }

    public function test_un_municipio_cuyo_anexo_solo_esta_en_pdf_queda_pendiente_sin_recibir_las_fiestas_de_la_capital(): void
    {
        $this->fuentes();

        $resultado = app(FestivosValencia::class)->consultar(2026, $this->municipio('46131', 'Gandia', 'Valencia/València'));

        $this->assertSame('pendiente_revision', $resultado['estado']);
        $this->assertSame([], $resultado['fechas']);
        $this->assertStringContainsString('anexo PDF', $resultado['mensaje']);
        Http::assertNotSent(fn ($peticion) => $peticion->url() === FestivosValencia::MUNICIPAL);
    }

    public function test_no_confirma_si_los_datos_del_ayuntamiento_discrepan_de_una_rectificacion(): void
    {
        $rectificacion = $this->fixtureJson('gva-dogv-468020');
        $rectificacion['texto'] .= '<div>Relación de fiestas locales 2026 en la provincia de Valencia:</div><div>Donde dice: «VALÈNCIA: 22 de enero; 13 de abril.»;</div><div>Debe decir: «VALÈNCIA: 22 de enero; 14 de abril.»</div>';
        $this->fuentes([FestivosValencia::API.'/disposicion/468020?lang=es_es' => Http::response($rectificacion)]);

        $resultado = app(FestivosValencia::class)->consultar(2026, $this->municipio());

        $this->assertSame('conflicto', $resultado['estado']);
        $this->assertSame([], $resultado['fechas']);
        $this->assertStringContainsString('no coinciden', $resultado['mensaje']);
        Http::assertSentCount(9);
    }

    public function test_una_rectificacion_con_formato_no_reconocido_no_se_ignora(): void
    {
        $rectificacion = $this->fixtureJson('gva-dogv-468020');
        $rectificacion['texto'] = str_replace('Debe decir:', 'Nueva redacción:', $rectificacion['texto']);
        $this->fuentes([FestivosValencia::API.'/disposicion/468020?lang=es_es' => Http::response($rectificacion)]);

        $resultado = app(FestivosValencia::class)->consultar(2026, $this->municipio());

        $this->assertSame('pendiente_revision', $resultado['estado']);
        $this->assertSame([], $resultado['fechas']);
        $this->assertStringContainsString('rectificación', $resultado['mensaje']);
        Http::assertNotSent(fn ($peticion) => $peticion->url() === FestivosValencia::MUNICIPAL);
    }

    public function test_las_correcciones_adicionales_del_dogv_exigen_revision(): void
    {
        $correcciones = $this->fixtureJson('gva-dogv-454613-correcciones');
        $correcciones['analisisJuridicoPasivo'] = [['id' => 999999, 'descripcion' => 'Corrección posterior']];
        $this->fuentes([FestivosValencia::API.'/disposicion/454613/correccionErrores?lang=es_es' => Http::response($correcciones)]);

        $resultado = app(FestivosValencia::class)->consultar(2026, $this->municipio());

        $this->assertSame('pendiente_revision', $resultado['estado']);
        $this->assertSame([], $resultado['fechas']);
        $this->assertStringContainsString('correcciones adicionales', $resultado['mensaje']);
        Http::assertSentCount(8);
    }

    public function test_una_publicacion_de_otro_ano_o_fecha_futura_no_se_usa(): void
    {
        $base = $this->fixtureJson('gva-dogv-454613');
        $base['titulo'] = str_replace('año 2026', 'año 2025', $base['titulo']);
        $base['fechaPublicacion'] = '2027-01-01';
        $this->fuentes([FestivosValencia::API.'/disposicion/454613?lang=es_es' => Http::response($base)]);

        $resultado = app(FestivosValencia::class)->consultar(2026, $this->municipio());

        $this->assertSame('pendiente_revision', $resultado['estado']);
        $this->assertSame([], $resultado['fechas']);
        $this->assertStringContainsString('publicación', $resultado['mensaje']);
        Http::assertNotSent(fn ($peticion) => $peticion->url() === FestivosValencia::MUNICIPAL);
    }

    public function test_el_calendario_municipal_con_fecha_imposible_no_se_confirma(): void
    {
        $municipal = str_replace('22 Enero', '30 Febrero', $this->fixture('gva-valencia-municipal-2026.html'));
        $this->fuentes([FestivosValencia::MUNICIPAL => Http::response($municipal)]);

        $resultado = app(FestivosValencia::class)->consultar(2026, $this->municipio());

        $this->assertSame('pendiente_revision', $resultado['estado']);
        $this->assertSame([], $resultado['fechas']);
        Http::assertSentCount(9);
    }

    public function test_un_error_de_conexion_no_se_presenta_como_fechas_confirmadas_o_ausencia_de_fiestas(): void
    {
        $this->fuentes([FestivosValencia::INDICE => Http::failedConnection()]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No se puede conectar');

        app(FestivosValencia::class)->consultar(2026, $this->municipio());
    }

    public function test_no_acepta_una_identidad_fuera_de_la_comunidad_o_provincia_del_ine(): void
    {
        $municipio = $this->municipio('46250', 'València', 'Alicante/Alacant');

        $resultado = app(FestivosValencia::class)->consultar(2026, $municipio);

        $this->assertSame('pendiente_revision', $resultado['estado']);
        $this->assertSame([], $resultado['fechas']);
        Http::assertNothingSent();
    }

    /** @param list<string> $fechas */
    #[DataProvider('municipiosRectificados')]
    public function test_las_rectificaciones_comprueban_los_otros_municipios_con_alias_ine_controlado(string $ine, string $nombre, string $provincia, array $fechas): void
    {
        $this->fuentes();

        $resultado = app(FestivosValencia::class)->consultar(2026, $this->municipio($ine, $nombre, $provincia));

        $this->assertSame('confirmado', $resultado['estado']);
        $this->assertSame($fechas, $resultado['fechas']);
        Http::assertSentCount(8);
    }

    /** @return array<string,array{string,string,string,list<string>}> */
    public static function municipiosRectificados(): array
    {
        return [
            'Alcosser corrige mayo por marzo' => ['03007', 'Alcosser', 'Alicante/Alacant', ['2026-03-20', '2026-12-07']],
            'Onil sustituye noviembre por abril' => ['03096', 'Onil', 'Alicante/Alacant', ['2026-04-23', '2026-04-30']],
            'Penàguila coincide con Penáguila del DOGV' => ['03103', 'Penàguila', 'Alicante/Alacant', ['2026-08-24', '2026-12-07']],
            'Villamalur sustituye septiembre por agosto' => ['12131', 'Villamalur', 'Castellón/Castelló', ['2026-05-11', '2026-08-24']],
        ];
    }

    public function test_no_aplica_las_fechas_de_orba_a_otro_ine_de_la_misma_provincia(): void
    {
        $resultado = app(FestivosValencia::class)->consultar(2026, $this->municipio('03096', 'Orba', 'Alicante/Alacant'));

        $this->assertSame('pendiente_revision', $resultado['estado']);
        $this->assertSame([], $resultado['fechas']);
        Http::assertNothingSent();
    }

    public function test_el_ano_de_la_tabla_municipal_debe_ser_el_solicitado(): void
    {
        $municipal = str_replace('para 2026', 'para 2025', $this->fixture('gva-valencia-municipal-2026.html'));
        $this->fuentes([FestivosValencia::MUNICIPAL => Http::response($municipal)]);

        $resultado = app(FestivosValencia::class)->consultar(2026, $this->municipio());

        $this->assertSame('pendiente_revision', $resultado['estado']);
        $this->assertSame([], $resultado['fechas']);
        Http::assertSentCount(9);
    }

    public function test_un_error_http_oficial_no_se_trata_como_ausencia_de_fiestas(): void
    {
        $this->fuentes([FestivosValencia::INDICE => Http::response([], 500)]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 500');

        app(FestivosValencia::class)->consultar(2026, $this->municipio());
    }

    /** @return array{ine:string,nombre:string,provincia:string,comunidad_codigo:string} */
    private function municipio(string $ine = '46250', string $nombre = 'València', string $provincia = 'Valencia/València'): array
    {
        return ['ine' => $ine, 'nombre' => $nombre, 'provincia' => $provincia, 'comunidad_codigo' => 'ES-VC'];
    }

    private function fixture(string $nombre): string
    {
        return file_get_contents(base_path('tests/Fixtures/Calendario/'.$nombre));
    }

    /** @return array<string,mixed> */
    private function fixtureJson(string $nombre): array
    {
        return json_decode($this->fixture($nombre.'.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $respuestas */
    private function fuentes(array $respuestas = []): void
    {
        Http::fake($respuestas + [
            FestivosValencia::INDICE => Http::response($this->fixture('gva-indice-2026-10-30.html')),
            FestivosValencia::MUNICIPAL => Http::response($this->fixture('gva-valencia-municipal-2026.html')),
            FestivosValencia::API.'/dogv/obtenerIdDogv/2025-46326' => Http::response('454613'),
            FestivosValencia::API.'/dogv/obtenerIdDogv/2026-1043' => Http::response('462310'),
            FestivosValencia::API.'/dogv/obtenerIdDogv/2026-6733' => Http::response('468020'),
            FestivosValencia::API.'/disposicion/454613?lang=es_es' => Http::response($this->fixtureJson('gva-dogv-454613')),
            FestivosValencia::API.'/disposicion/462310?lang=es_es' => Http::response($this->fixtureJson('gva-dogv-462310')),
            FestivosValencia::API.'/disposicion/468020?lang=es_es' => Http::response($this->fixtureJson('gva-dogv-468020')),
            FestivosValencia::API.'/disposicion/454613/correccionErrores?lang=es_es' => Http::response($this->fixtureJson('gva-dogv-454613-correcciones')),
        ]);
    }
}
