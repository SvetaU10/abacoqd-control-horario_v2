<?php

namespace Tests\Feature;

use App\Models\Municipio;
use App\Services\CatalogoMunicipios;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class CatalogoMunicipiosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_la_instalacion_incluye_el_catalogo_nacional_sin_red_y_con_procedencia(): void
    {
        $catalogo = app(CatalogoMunicipios::class);
        $estado = $catalogo->estado();

        $this->assertTrue($estado['disponible']);
        $this->assertSame(8132, $estado['total']);
        $this->assertSame(8132, Municipio::query()->count());
        $this->assertSame(52, Municipio::query()->distinct()->count('provincia_codigo'));
        $this->assertSame(CatalogoMunicipios::URL, $estado['origen']);
        $this->assertSame('https://www.ine.es/daco/daco42/codmun/diccionario26.xlsx', $estado['referencia']);
        $this->assertNotEmpty($estado['actualizado_at']);
        $this->assertSame(64, strlen($estado['sha256']));
        $this->assertSame('01001', $catalogo->resolver('01001')['ine']);
        Http::assertNothingSent();
    }

    public function test_la_busqueda_distingue_homonimos_y_acepta_acentos_y_codigos_con_ceros(): void
    {
        $catalogo = app(CatalogoMunicipios::class);

        $this->assertSame('46250', $catalogo->buscar('Valencia')[0]['ine']);
        $this->assertSame('València', $catalogo->buscar('VALÈNCIA')[0]['nombre']);
        $this->assertSame('01001', $catalogo->buscar('01001')[0]['ine']);
        $this->assertSame('Segovia', $catalogo->buscar('Segovia')[0]['nombre']);
        $cieza = array_values(array_filter($catalogo->buscar('Cieza'), fn (array $fila): bool => $fila['nombre'] === 'Cieza'));
        $this->assertCount(2, $cieza);
        $this->assertCount(2, array_unique(array_column($cieza, 'provincia_codigo')));
        $this->assertSame('30019', $catalogo->buscar('Cieza Murcia')[0]['ine']);
        $this->assertSame([], $catalogo->buscar('%_'));
        $this->assertSame([], $catalogo->buscar(',,'));
        Http::assertNothingSent();
    }

    public function test_cada_busqueda_es_limitada_y_la_cache_evitan_releer_las_filas(): void
    {
        $catalogo = app(CatalogoMunicipios::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $primera = $catalogo->buscar('san');
            $segunda = $catalogo->buscar('san');
            $consultas = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertCount(25, $primera);
        $this->assertSame($primera, $segunda);
        $this->assertLessThanOrEqual(3, count($consultas));
        $this->assertCount(26, $catalogo->buscar('san', 1000));
        Http::assertNothingSent();
    }

    public function test_el_articulo_adelantado_encuentra_la_ciudad_antes_que_su_provincia(): void
    {
        $catalogo = app(CatalogoMunicipios::class);

        $coruna = $catalogo->buscar('A Coruña');
        $palmas = $catalogo->buscar('Las Palmas de Gran Canaria');

        $this->assertSame('15030', $coruna[0]['ine']);
        $this->assertSame('Coruña, A', $coruna[0]['nombre']);
        $this->assertSame('35016', $palmas[0]['ine']);
        $this->assertSame('Palmas de Gran Canaria, Las', $palmas[0]['nombre']);
        $this->assertSame('15030', $catalogo->buscar('Coruña')[0]['ine']);
        $this->assertSame('35016', $catalogo->buscar('Las Palmas')[0]['ine']);
        Http::assertNothingSent();
    }

    public function test_el_nombre_municipal_precede_a_coincidencias_solo_de_provincia_y_conserva_la_cache(): void
    {
        $catalogo = app(CatalogoMunicipios::class);
        $estado = $catalogo->estado();
        $claveAnterior = 'municipios:'.$estado['sha256'].':'.hash('sha256', 'a coruna:25');
        Cache::put($claveAnterior, [['ine' => '15001', 'nombre' => 'Resultado de una clasificación anterior']], now()->addHour());

        $primera = $catalogo->buscar('A Coruña');
        $repetida = $catalogo->buscar('A Coruña');

        $this->assertSame('15030', $primera[0]['ine']);
        $this->assertSame($primera, $repetida);
        $this->assertSame('28079', $catalogo->buscar('Madrid')[0]['ine']);
        $this->assertSame('46250', $catalogo->buscar('Valencia')[0]['ine']);
        $this->assertSame('València', $catalogo->resolver('46250')['nombre']);
        $this->assertSame('Coruña, A', $catalogo->resolver('15030')['nombre']);
        Http::assertNothingSent();
    }

    public function test_una_actualizacion_completa_cambia_el_catalogo_y_la_cache_sin_perder_ceros(): void
    {
        $catalogo = app(CatalogoMunicipios::class);
        $anterior = $catalogo->estado();
        $nombreAnterior = $catalogo->buscar('01001')[0]['nombre'];
        $datos = $this->snapshot();
        $datos['municipalities'][0]['name'] = 'Nombre municipal actualizado';
        Http::fake([CatalogoMunicipios::URL => Http::response($datos)]);

        $actual = $catalogo->actualizar();

        $this->assertNotSame($anterior['sha256'], $actual['sha256']);
        $this->assertNotSame($nombreAnterior, $catalogo->buscar('01001')[0]['nombre']);
        $this->assertSame('Nombre municipal actualizado', $catalogo->resolver('01001')['nombre']);
        $this->assertSame(8132, Municipio::query()->count());
        $this->assertSame($actual, $catalogo->instalarSnapshot());
        Http::assertSentCount(1);
    }

    public function test_las_descargas_incompletas_duplicadas_o_incoherentes_conservan_la_version_local(): void
    {
        $catalogo = app(CatalogoMunicipios::class);
        $anterior = $catalogo->estado();
        $incompleto = $duplicado = $incoherente = $this->snapshot();
        array_pop($incompleto['municipalities']);
        $duplicado['municipalities'][1] = $duplicado['municipalities'][0];
        $incoherente['municipalities'][0]['ccaa_iso'] = 'ES-MD';
        Http::fake([CatalogoMunicipios::URL => Http::sequence()->push($incompleto)->push($duplicado)->push($incoherente)->push('no es JSON')->push([], 503)]);

        for ($intento = 0; $intento < 5; $intento++) {
            try {
                $catalogo->actualizar();
                $this->fail('Una descarga inválida no debe sustituir el catálogo vigente.');
            } catch (RuntimeException) {
                $this->assertSame($anterior, $catalogo->estado());
                $this->assertSame(8132, Municipio::query()->count());
            }
        }
    }

    public function test_volver_a_una_version_anterior_utiliza_su_cache_y_no_la_del_catalogo_nuevo(): void
    {
        $catalogo = app(CatalogoMunicipios::class);
        $anterior = $catalogo->estado();
        $nombre = $catalogo->buscar('01001')[0]['nombre'];
        $datos = $nuevos = $this->snapshot();
        $nuevos['municipalities'][0]['name'] = 'Otra versión del municipio';
        Http::fake([CatalogoMunicipios::URL => Http::sequence()->push($nuevos)->push($datos)]);

        $catalogo->actualizar();
        $this->assertSame('Otra versión del municipio', $catalogo->buscar('01001')[0]['nombre']);
        $catalogo->actualizar();

        $this->assertSame($anterior['sha256'], $catalogo->estado()['sha256']);
        $this->assertSame($nombre, $catalogo->buscar('01001')[0]['nombre']);
    }

    public function test_un_fallo_durante_la_escritura_revierte_tambien_el_borrado_y_los_metadatos(): void
    {
        $catalogo = app(CatalogoMunicipios::class);
        $anterior = $catalogo->estado();
        $original = $catalogo->resolver('01001');
        $datos = $this->snapshot();
        $datos['municipalities'][0]['name'] = 'Cambio que se debe revertir';
        Http::fake([CatalogoMunicipios::URL => Http::response($datos)]);
        DB::statement("CREATE TRIGGER impedir_catalogo BEFORE INSERT ON municipios WHEN NEW.ine = '46250' BEGIN SELECT RAISE(ABORT, 'fallo de prueba'); END");

        try {
            $catalogo->actualizar();
            $this->fail('La escritura interrumpida debe fallar.');
        } catch (RuntimeException) {
            $this->assertSame($anterior, $catalogo->estado());
            $this->assertSame($original, $catalogo->resolver('01001'));
            $this->assertSame(8132, Municipio::query()->count());
        }
    }

    public function test_un_catalogo_ausente_no_se_descarga_ni_instala_por_cada_busqueda(): void
    {
        DB::table('catalogo_municipios')->delete();
        DB::table('municipios')->delete();
        $catalogo = app(CatalogoMunicipios::class);
        try {
            $catalogo->buscar('Madrid');
            $this->fail('Debe informar de que falta la instalación.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('no está instalado', $error->getMessage());
            $this->assertSame(0, Municipio::query()->count());
        }

        $this->artisan('catalogo:actualizar', ['--snapshot' => true])->assertSuccessful();
        $this->assertSame(8132, $catalogo->estado()['total']);
        Http::assertNothingSent();
    }

    public function test_un_timeout_del_comando_no_elimina_el_catalogo_vigente(): void
    {
        $anterior = app(CatalogoMunicipios::class)->estado();
        Http::fake([CatalogoMunicipios::URL => Http::failedConnection()]);

        $this->artisan('catalogo:actualizar')->expectsOutputToContain('Se conserva la versión local')->assertFailed();

        $this->assertSame($anterior, app(CatalogoMunicipios::class)->estado());
        $this->assertSame(8132, Municipio::query()->count());
    }

    public function test_un_codigo_ajeno_no_se_convierte_en_otro_municipio(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(CatalogoMunicipios::class)->resolver('1001');
    }

    /** @return array<string,mixed> */
    private function snapshot(): array
    {
        return json_decode(file_get_contents(database_path(CatalogoMunicipios::SNAPSHOT)), true, flags: JSON_THROW_ON_ERROR);
    }
}
