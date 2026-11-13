<?php

namespace Tests\Feature;

use App\Services\FestivosBoe;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FestivosBoeTest extends TestCase
{
    public function test_lee_las_fiestas_generales_de_castilla_y_leon_en_el_boe_real_2026(): void
    {
        Http::fake([$this->url() => Http::response($this->publicacion(), 200, ['Content-Type' => 'application/xml'])]);

        $resultado = app(FestivosBoe::class)->consultar(2026, 'ES-CL', $this->referencia());

        $this->assertSame('confirmado', $resultado['estado']);
        $this->assertSame(['2026-01-01', '2026-01-06', '2026-04-02', '2026-04-03', '2026-04-23', '2026-05-01', '2026-08-15', '2026-10-12', '2026-11-02', '2026-12-07', '2026-12-08', '2026-12-25'], $resultado['fechas']);
        $this->assertSame('comunidad', collect($resultado['dias'])->firstWhere('date', '2026-04-23')['ambito']);
        Http::assertSentCount(1);
    }

    public function test_lee_valencia_y_no_hereda_las_fiestas_de_otra_comunidad(): void
    {
        Http::fake([$this->url() => Http::response($this->publicacion())]);

        $resultado = app(FestivosBoe::class)->consultar(2026, 'ES-VC', $this->referencia());

        $this->assertSame('confirmado', $resultado['estado']);
        $this->assertSame(['2026-01-01', '2026-01-06', '2026-03-19', '2026-04-03', '2026-04-06', '2026-05-01', '2026-06-24', '2026-08-15', '2026-10-09', '2026-10-12', '2026-12-08', '2026-12-25'], $resultado['fechas']);
        Http::assertSentCount(1);
    }

    public function test_el_formato_del_boe_2027_sin_cabeceras_axis_tambien_se_reconoce(): void
    {
        $url = 'https://www.boe.es/diario_boe/xml.php?id=BOE-A-2026-21254';
        Http::fake([$url => Http::response(file_get_contents(base_path('tests/Fixtures/Calendario/boe-2027.xml')))]);

        $resultado = app(FestivosBoe::class)->consultar(2027, 'ES-VC', [['fuente' => 'BOE-A-2026-21254', 'ambito' => 'estado']]);

        $this->assertSame('confirmado', $resultado['estado']);
        $this->assertSame(['2027-01-01', '2027-01-06', '2027-03-19', '2027-03-26', '2027-03-29', '2027-05-01', '2027-10-09', '2027-10-12', '2027-11-01', '2027-12-06', '2027-12-08', '2027-12-25'], $resultado['fechas']);
        Http::assertSentCount(1);
    }

    public function test_no_confirma_otro_ano_ni_una_tabla_que_ha_perdido_columnas(): void
    {
        Http::fake([$this->url() => Http::sequence()->push($this->publicacion())->push(str_replace('<th axis="comunidad" class="cuerpo_tabla_centro" id="headerValencia">Comunitat Valenciana</th>', '', $this->publicacion()))]);

        $servicio = app(FestivosBoe::class);
        $otroAnio = $servicio->consultar(2027, 'ES-CL', $this->referencia());
        $tablaIncompleta = $servicio->consultar(2026, 'ES-CL', $this->referencia());

        $this->assertSame('pendiente_revision', $otroAnio['estado']);
        $this->assertSame([], $otroAnio['fechas']);
        $this->assertSame('pendiente_revision', $tablaIncompleta['estado']);
        Http::assertSentCount(2);
    }

    public function test_una_caida_o_xml_invalido_no_confirma_cobertura(): void
    {
        Http::fake([$this->url() => Http::sequence()->pushStatus(503)->push('<html>Sin datos</html>')->push('<!DOCTYPE documento [<!ENTITY dato SYSTEM "file:///etc/passwd">]><documento/>')]);

        foreach ([1, 2, 3] as $intento) {
            $resultado = app(FestivosBoe::class)->consultar(2026, 'ES-CL', $this->referencia());
            $this->assertSame('pendiente_revision', $resultado['estado']);
            $this->assertSame([], $resultado['fechas']);
        }
        Http::assertSentCount(3);
    }

    public function test_una_excepcion_territorial_o_referencia_ambigua_requiere_revision(): void
    {
        $servicio = app(FestivosBoe::class);

        $sinReferencia = $servicio->consultar(2026, 'ES-CL', []);
        $canarias = $servicio->consultar(2026, 'ES-CN', $this->referencia());
        $ambigua = $servicio->consultar(2026, 'ES-VC', [...$this->referencia(), ['fuente' => 'BOE-A-2026-21254', 'ambito' => 'estado']]);

        $this->assertSame('pendiente_revision', $sinReferencia['estado']);
        $this->assertSame('pendiente_revision', $canarias['estado']);
        $this->assertSame('pendiente_revision', $ambigua['estado']);
        Http::assertNothingSent();
    }

    private function publicacion(): string
    {
        return file_get_contents(base_path('tests/Fixtures/Calendario/boe-2026.xml'));
    }

    private function url(): string
    {
        return 'https://www.boe.es/diario_boe/xml.php?id=BOE-A-2025-21667';
    }

    private function referencia(): array
    {
        return [['fuente' => 'BOE-A-2025-21667', 'ambito' => 'estado']];
    }
}
