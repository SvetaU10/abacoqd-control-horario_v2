<?php

namespace Tests\Feature;

use App\Services\FestivosEspana;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FestivosEspanaTest extends TestCase
{
    #[DataProvider('paresReales')]
    public function test_contrasta_cualquier_ine_y_conserva_las_discrepancias_reales(string $ine, string $estado): void
    {
        $this->fuente($this->datos('espana-'.$ine.'-2026'));

        $resultado = app(FestivosEspana::class)->consultar(2026, $this->municipio($ine), $this->festivos($ine));

        $this->assertSame($estado, $resultado['estado'], $resultado['mensaje']);
        $this->assertNotEmpty($resultado['fuentes']);
        if ($estado === 'confirmado') {
            $this->assertStringContainsString('concordancia entre proveedores', $resultado['mensaje']);
            $this->assertStringNotContainsString('contrastadas con el calendario oficial', $resultado['mensaje']);
        }
        Http::assertSent(fn ($peticion) => str_contains($peticion->url(), 'ine='.$ine) && str_contains($peticion->url(), 'year=2026'));
    }

    public static function paresReales(): array
    {
        return [
            'Barcelona' => ['08019', 'confirmado'],
            'Sevilla' => ['41091', 'confirmado'],
            'Bilbao discrepa en31julio provincial publicado como local' => ['48020', 'conflicto'],
            'Tenerife discrepa por fiesta insular' => ['38038', 'conflicto'],
            'Formentera isla pequeña' => ['07024', 'confirmado'],
            'Palma isla grande' => ['07040', 'confirmado'],
            'Cantillana municipio fuera de las capitales' => ['41023', 'confirmado'],
            'Aran discrepa en17junio frente26diciembre' => ['25243', 'conflicto'],
            'Valverde isla del Hierro' => ['38048', 'conflicto'],
        ];
    }

    public function test_no_confirma_un_municipio_distinto_aunque_tenga_catorce_fechas(): void
    {
        $datos = $this->datos('espana-08019-2026');
        $datos['municipio']['ine'] = '08020';
        $this->fuente($datos);

        $resultado = $this->consultarBarcelona();

        $this->assertSame('conflicto', $resultado['estado']);
        Http::assertSentCount(1);
    }

    public function test_no_confirma_un_ano_distinto_ni_reasigna_sus_fechas(): void
    {
        $datos = $this->datos('espana-08019-2026');
        $datos['anios'][0]['year'] = 2025;
        $this->fuente($datos);

        $resultado = $this->consultarBarcelona();

        $this->assertSame('conflicto', $resultado['estado']);
        Http::assertSentCount(1);
    }

    public function test_nacional_o_local_sin_confirmar_no_se_infiere_por_el_numero_de_fechas(): void
    {
        $datos = $this->datos('espana-08019-2026');
        $datos['anios'][0]['localOk'] = false;
        $this->fuente($datos);

        $resultado = $this->consultarBarcelona();

        $this->assertSame('parcial', $resultado['estado']);
        Http::assertSentCount(1);
    }

    public function test_las_marcas_de_verificacion_ausentes_no_equivalen_a_confirmadas(): void
    {
        $datos = $this->datos('espana-08019-2026');
        unset($datos['anios'][0]['nacionalOk']);
        $this->fuente($datos);

        $resultado = $this->consultarBarcelona();

        $this->assertSame('parcial', $resultado['estado']);
        Http::assertSentCount(1);
    }

    public function test_una_fila_provisional_impide_confirmar_aunque_las_dos_capas_digan_ok(): void
    {
        $datos = $this->datos('espana-08019-2026');
        $datos['anios'][0]['festivos'][0]['estado'] = 'provisional';
        $this->fuente($datos);

        $resultado = $this->consultarBarcelona();

        $this->assertSame('parcial', $resultado['estado']);
        Http::assertSentCount(1);
    }

    public function test_una_fecha_imposible_no_se_carga(): void
    {
        $datos = $this->datos('espana-08019-2026');
        $datos['anios'][0]['festivos'][0]['fecha'] = '2026-02-30';
        $this->fuente($datos);

        $resultado = $this->consultarBarcelona();

        $this->assertSame('pendiente_revision', $resultado['estado']);
        $this->assertStringContainsString('fecha', $resultado['mensaje']);
        Http::assertSentCount(1);
    }

    public function test_una_clasificacion_local_o_general_distinta_es_un_conflicto(): void
    {
        $datos = $this->datos('espana-08019-2026');
        $datos['anios'][0]['festivos'][0]['ambito'] = 'local';
        $this->fuente($datos);

        $resultado = $this->consultarBarcelona();

        $this->assertSame('conflicto', $resultado['estado']);
        Http::assertSentCount(1);
    }

    public function test_no_inventa_la_fiesta_provincial_o_insular_sin_una_categoria_acreditada(): void
    {
        $datos = $this->datos('espana-08019-2026');
        $datos['anios'][0]['festivos'][0]['ambito'] = 'provincial';
        $this->fuente($datos);

        $resultado = $this->consultarBarcelona();

        $this->assertSame('pendiente_revision', $resultado['estado']);
        $this->assertStringContainsString('clasificación', $resultado['mensaje']);
        Http::assertSentCount(1);
    }

    public function test_si_ambos_omiten_las_fiestas_locales_no_hay_cobertura_completa(): void
    {
        $datos = $this->datos('espana-08019-2026');
        $datos['anios'][0]['festivos'] = array_values(array_filter($datos['anios'][0]['festivos'], fn (array $dia): bool => $dia['ambito'] !== 'local'));
        $this->fuente($datos);
        $festivos = array_values(array_filter($this->festivos('08019'), fn (array $dia): bool => $dia['ambito'] !== 'pueblo'));

        $resultado = app(FestivosEspana::class)->consultar(2026, $this->municipio('08019'), $festivos);

        $this->assertSame('parcial', $resultado['estado']);
        Http::assertSentCount(1);
    }

    public function test_dos_fuentes_recortadas_no_confirman_cobertura_aunque_sus_marcas_digan_verificado(): void
    {
        $fechas = ['2026-01-01', '2026-05-25'];
        $datos = $this->datos('espana-08019-2026');
        $datos['anios'][0]['festivos'] = array_values(array_filter($datos['anios'][0]['festivos'], fn (array $dia): bool => in_array($dia['fecha'], $fechas, true)));
        $this->fuente($datos);
        $festivos = array_values(array_filter($this->festivos('08019'), fn (array $dia): bool => in_array($dia['date'], $fechas, true)));

        $resultado = app(FestivosEspana::class)->consultar(2026, $this->municipio('08019'), $festivos);

        $this->assertSame('parcial', $resultado['estado']);
        $this->assertStringContainsString('cobertura suficiente', $resultado['mensaje']);
        Http::assertSentCount(1);
    }

    public function test_mas_de_dos_locales_no_se_confirman_aunque_coincidan_los_dos_proveedores(): void
    {
        $datos = $this->datos('espana-08019-2026');
        $datos['anios'][0]['festivos'][] = ['fecha' => '2026-11-13', 'nombre' => 'Tercera fiesta local', 'ambito' => 'local', 'estado' => 'verificado'];
        $this->fuente($datos);
        $festivos = $this->festivos('08019');
        $festivos[] = ['date' => '2026-11-13', 'name' => 'Tercera fiesta local', 'ambito' => 'pueblo', 'fuente' => 'Referencia local', 'url' => 'https://bop.diba.cat/'];

        $resultado = app(FestivosEspana::class)->consultar(2026, $this->municipio('08019'), $festivos);

        $this->assertSame('parcial', $resultado['estado']);
        Http::assertSentCount(1);
    }

    public function test_no_cuenta_dos_veces_una_fecha_general_presentada_como_nacional_y_autonomica(): void
    {
        $datos = $this->datos('espana-08019-2026');
        $generales = array_slice(array_values(array_filter($datos['anios'][0]['festivos'], fn (array $dia): bool => $dia['ambito'] !== 'local')), 0, 6);
        $locales = array_values(array_filter($datos['anios'][0]['festivos'], fn (array $dia): bool => $dia['ambito'] === 'local'));
        $filas = [];
        $festivos = [];
        foreach ($generales as $dia) {
            foreach (['nacional' => 'estado', 'autonomico' => 'comunidad'] as $ambito => $normalizado) {
                $filas[] = [...$dia, 'ambito' => $ambito];
                $festivos[] = ['date' => $dia['fecha'], 'name' => $dia['nombre'], 'ambito' => $normalizado];
            }
        }
        $datos['anios'][0]['festivos'] = [...$filas, ...$locales];
        $festivos = [...$festivos, ...array_values(array_filter($this->festivos('08019'), fn (array $dia): bool => $dia['ambito'] === 'pueblo'))];
        $this->fuente($datos);

        $resultado = app(FestivosEspana::class)->consultar(2026, $this->municipio('08019'), $festivos);

        $this->assertSame('parcial', $resultado['estado']);
        Http::assertSentCount(1);
    }

    public function test_las_referencias_oficiales_del_primer_proveedor_se_conservan(): void
    {
        $this->fuente($this->datos('espana-08019-2026'));

        $resultado = $this->consultarBarcelona();

        $this->assertContains('https://www.boe.es/diario_boe/txt.php?id=BOE-A-2025-21667', array_column($resultado['fuentes'], 'url'));
        $this->assertContains(FestivosEspana::DOCUMENTACION, array_column($resultado['fuentes'], 'url'));
        Http::assertSentCount(1);
    }

    public function test_la_comparacion_admite_los_nombres_bilingues_del_catalogo(): void
    {
        $this->fuente($this->datos('espana-07040-2026'));
        $municipio = $this->municipio('07040');
        $municipio['provincia'] = 'Balears, Illes';

        $resultado = app(FestivosEspana::class)->consultar(2026, $municipio, $this->festivos('07040'));

        $this->assertSame('confirmado', $resultado['estado']);
        Http::assertSentCount(1);
    }

    public function test_el_404_indica_datos_no_disponibles_para_ese_municipio_y_ano(): void
    {
        Http::fake([FestivosEspana::API.'*' => Http::response([], 404)]);

        $resultado = $this->consultarBarcelona();

        $this->assertSame('parcial', $resultado['estado']);
        $this->assertStringContainsString('todavía no ofrece datos', $resultado['mensaje']);
        Http::assertSentCount(1);
    }

    public function test_un_503_es_error_del_proveedor_y_no_ausencia_de_fiestas(): void
    {
        Http::fake([FestivosEspana::API.'*' => Http::response([], 503)]);

        $resultado = $this->consultarBarcelona();

        $this->assertSame('pendiente_revision', $resultado['estado']);
        $this->assertStringContainsString('HTTP 503', $resultado['mensaje']);
        Http::assertSentCount(1);
    }

    public function test_un_timeout_no_confirma_ni_borra_calendarios(): void
    {
        Http::fake([FestivosEspana::API.'*' => Http::failedConnection()]);

        $resultado = $this->consultarBarcelona();

        $this->assertSame('pendiente_revision', $resultado['estado']);
        $this->assertStringContainsString('conserva el calendario válido', $resultado['mensaje']);
        Http::assertSentCount(1);
    }

    public function test_json_malformado_no_se_confirma(): void
    {
        Http::fake([FestivosEspana::API.'*' => Http::response('<html>No disponible</html>')]);

        $resultado = $this->consultarBarcelona();

        $this->assertSame('pendiente_revision', $resultado['estado']);
        Http::assertSentCount(1);
    }

    public function test_no_se_pueden_inyectar_parametros_mediante_el_codigo_ine(): void
    {
        $municipio = $this->municipio('08019');
        $municipio['ine'] = '08019&year=2025';

        $resultado = app(FestivosEspana::class)->consultar(2026, $municipio, []);

        $this->assertSame('pendiente_revision', $resultado['estado']);
        Http::assertNothingSent();
    }

    public function test_la_cache_limita_consultas_y_separa_municipio_y_ano(): void
    {
        Http::fake([FestivosEspana::API.'*' => Http::sequence()
            ->push($this->datos('espana-08019-2026'))
            ->push($this->datos('espana-41091-2026'))
            ->push($this->datos('espana-08019-2027'))]);
        $this->consultarBarcelona();

        $repetido = $this->consultarBarcelona();
        $sevilla = app(FestivosEspana::class)->consultar(2026, $this->municipio('41091'), $this->festivos('41091'));
        $siguiente = app(FestivosEspana::class)->consultar(2027, $this->municipio('08019'), $this->festivos('08019', 2027));

        $this->assertSame('confirmado', $repetido['estado']);
        $this->assertSame('confirmado', $sevilla['estado']);
        $this->assertSame('parcial', $siguiente['estado']);
        Http::assertSentCount(3);
    }

    private function consultarBarcelona(): array
    {
        return app(FestivosEspana::class)->consultar(2026, $this->municipio('08019'), $this->festivos('08019'));
    }

    private function fuente(array $datos): void
    {
        Http::fake([FestivosEspana::API.'*' => Http::response($datos)]);
    }

    private function datos(string $nombre): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/Calendario/'.$nombre.'.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function municipio(string $ine): array
    {
        $datos = $this->datos('festivosio-'.$ine.'-2026')['municipality'];

        return ['ine' => $datos['ine'], 'nombre' => $datos['name'], 'provincia' => $datos['province']['name'], 'comunidad_codigo' => $datos['ccaa']['code']];
    }

    private function festivos(string $ine, int $anio = 2026): array
    {
        return array_map(fn (array $dia): array => [
            'date' => $dia['date'], 'name' => $dia['name']['es'],
            'ambito' => match ($dia['level']) {
                'national' => 'estado', 'regional' => 'comunidad', 'local' => 'pueblo'
            },
            'fuente' => $dia['source']['ref'], 'url' => $dia['source']['url'],
        ], $this->datos('festivosio-'.$ine.'-'.$anio)['holidays']);
    }
}
