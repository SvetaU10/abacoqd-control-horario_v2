<?php

namespace Tests\Feature;

use App\Services\ReferenciaOficialCalendario;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReferenciaOficialCalendarioTest extends TestCase
{
    public function test_el_registro_abarca_los_boletines_autonomicos_y_provinciales_del_directorio_real_del_boe(): void
    {
        $documento = new DOMDocument;
        $anterior = libxml_use_internal_errors(true);
        $documento->loadHTML(file_get_contents(base_path('tests/Fixtures/Calendario/boe-diarios-oficiales.html')), LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);
        $enlaces = (new DOMXPath($documento))->query('//*[@id="boletines_autonomicos" or @id="boletines_provinciales"]//a[starts-with(@href,"http")]');
        $servicio = app(ReferenciaOficialCalendario::class);

        $this->assertCount(72, $enlaces);
        foreach ($enlaces as $enlace) {
            $url = preg_replace('/^http:/', 'https:', $enlace->getAttribute('href'));
            $this->assertTrue($servicio->esOficial($url), $url);
        }
        Http::assertNothingSent();
    }

    public function test_los_boletines_provinciales_no_dependen_de_los_ejemplos_municipales(): void
    {
        $servicio = app(ReferenciaOficialCalendario::class);

        $this->assertTrue($servicio->esOficial('https://bop.diba.cat/anunci/123'));
        $this->assertTrue($servicio->esOficial('https://www.dipusevilla.es/bop/'));
        $this->assertTrue($servicio->esOficial('https://bop.dival.es/bop/'));
        $this->assertTrue($servicio->esOficial('https://bomemelilla.es/'));
        Http::assertNothingSent();
    }

    public function test_no_confunde_dominios_parecidos_blog_o_enlaces_peligrosos_con_un_boletin(): void
    {
        $servicio = app(ReferenciaOficialCalendario::class);

        $this->assertFalse($servicio->esOficial('https://bop.diba.cat.ejemplo.com/'));
        $this->assertFalse($servicio->esOficial('https://blog.ejemplo.com/fiestas'));
        $this->assertFalse($servicio->esOficial('javascript:alert(1)'));
        $this->assertFalse($servicio->esOficial('https://usuario@bop.diba.cat/'));
        $this->assertFalse($servicio->esOficial('https://bop.diba.cat:8443/'));
        $this->assertFalse($servicio->esOficial('http://bop.diba.cat/'));
        Http::assertNothingSent();
    }
}
