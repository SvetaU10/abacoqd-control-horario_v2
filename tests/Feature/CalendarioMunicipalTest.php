<?php

namespace Tests\Feature;

use App\Models\Centro;
use App\Models\Festivo;
use App\Models\ImportacionCalendario;
use App\Models\User;
use App\Services\CalendarioMunicipal;
use App\Services\CatalogoMunicipios;
use App\Services\NagerFestivos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class CalendarioMunicipalTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_catalogo_deriva_provincia_y_comunidad_y_conserva_codigo(): void
    {
        $this->fuentes();

        $municipios = app(CalendarioMunicipal::class)->municipios('Madrid');
        $madrid = app(CalendarioMunicipal::class)->municipio('28079');

        $this->assertSame($madrid, $municipios[0]);
        $this->assertCount(26, $municipios);
        $this->assertSame(['ine' => '28079', 'nombre' => 'Madrid', 'provincia' => 'Madrid', 'provincia_codigo' => '28', 'comunidad' => 'Comunidad de Madrid', 'comunidad_codigo' => 'ES-MD'], $madrid);
        Http::assertNothingSent();
    }

    public function test_la_carga_contrastada_con_madrid_activa_el_calendario_local(): void
    {
        $this->fuentes();
        $jefa = User::factory()->jefa()->create();

        $consulta = app(CalendarioMunicipal::class)->cargar($jefa->sede, 2026, '28079', $jefa);

        $this->assertSame('cargado', $consulta->estado);
        $this->assertTrue($consulta->estaActivo());
        $this->assertCount(14, $consulta->festivos);
        $this->assertSame($jefa->id, $consulta->activado_por);
        $this->assertTrue(app(NagerFestivos::class)->coberturaConfirmada($jefa->sede, 2026));
        $dia = app(NagerFestivos::class)->delAnio($jefa->sede, 2026)->first(fn ($dia) => $dia->holiday_date->toDateString() === '2026-05-15');
        $this->assertSame('Festivo Local', $dia->etiqueta());
        Http::assertSentCount(3);
    }

    public function test_repetir_la_carga_no_duplica_la_version_activa(): void
    {
        $this->fuentes();
        $jefa = User::factory()->jefa()->create();
        $servicio = app(CalendarioMunicipal::class);
        $primera = $servicio->cargar($jefa->sede, 2026, '28079', $jefa);

        $segunda = $servicio->cargar($jefa->sede, 2026, '28079', $jefa);

        $this->assertSame('sin_cambios', $segunda->estado);
        $this->assertSame($primera->id, $servicio->activo($jefa->sede, 2026)->id);
        $this->assertSame($segunda->id, $servicio->ultima($jefa->sede, 2026)->id);
        $this->assertSame(1, ImportacionCalendario::query()->whereNotNull('clave_activa')->count());
        $this->assertSame(2, ImportacionCalendario::query()->count());
    }

    public function test_una_revision_reemplaza_la_fecha_anterior_y_conserva_su_historia(): void
    {
        $proveedor = $this->fixtureCalendario('madrid-2026');
        $nuevo = $proveedor;
        foreach ($nuevo['holidays'] as &$dia) {
            if ($dia['date'] === '2026-12-07') {
                $dia['date'] = '2026-12-06';
            }
        }
        unset($dia);
        $oficial = $this->fixtureCalendario('cam-regionales-2026');
        $oficialNuevo = $oficial;
        foreach ($oficialNuevo['data'] as &$dia) {
            if ($dia['fecha_festivo'] === '2026-12-07') {
                $dia['fecha_festivo'] = '2026-12-06';
            }
        }
        unset($dia);
        $this->fuentes([
            'https://festivos.io/v1/2026/municipio/28079.json' => Http::sequence()->push($proveedor)->push($nuevo),
            CalendarioMunicipal::CAM_REGIONALES => Http::sequence()->push($oficial)->push($oficialNuevo),
        ]);
        $jefa = User::factory()->jefa()->create();
        $servicio = app(CalendarioMunicipal::class);
        $primera = $servicio->cargar($jefa->sede, 2026, '28079', $jefa);

        $segunda = $servicio->cargar($jefa->sede, 2026, '28079', $jefa);

        $fechas = app(NagerFestivos::class)->delAnio($jefa->sede, 2026)->map(fn ($dia) => $dia->holiday_date->toDateString())->all();
        $this->assertSame('actualizado', $segunda->estado);
        $this->assertFalse($primera->fresh()->estaActivo());
        $this->assertContains('2026-12-06', $fechas);
        $this->assertNotContains('2026-12-07', $fechas);
        $this->assertCount(14, $fechas);
    }

    public function test_calendario_2027_parcial_no_se_activa_ni_reutiliza_2026(): void
    {
        $this->fuentes();
        $jefa = User::factory()->jefa()->create();

        $consulta = app(CalendarioMunicipal::class)->cargar($jefa->sede, 2027, '28079', $jefa);

        $this->assertSame('parcial', $consulta->estado);
        $this->assertFalse($consulta->estaActivo());
        $this->assertCount(12, $consulta->festivos);
        $this->assertFalse(app(NagerFestivos::class)->coberturaConfirmada($jefa->sede, 2027));
        $this->assertCount(0, app(NagerFestivos::class)->delAnio($jefa->sede, 2027));
        Http::assertSentCount(1);
    }

    public function test_una_consulta_parcial_del_mismo_anio_conserva_el_calendario_activo(): void
    {
        $completo = $this->fixtureCalendario('madrid-2026');
        $parcial = $completo;
        $parcial['local_status'] = 'pending';
        $parcial['holidays'] = array_values(array_filter($parcial['holidays'], fn ($dia) => $dia['level'] !== 'local'));
        $this->fuentes(['https://festivos.io/v1/2026/municipio/28079.json' => Http::sequence()->push($completo)->push($parcial)]);
        $jefa = User::factory()->jefa()->create();
        $servicio = app(CalendarioMunicipal::class);
        $activo = $servicio->cargar($jefa->sede, 2026, '28079', $jefa);

        $consulta = $servicio->cargar($jefa->sede, 2026, '28079', $jefa);

        $this->assertSame('parcial', $consulta->estado);
        $this->assertSame($activo->id, $servicio->activo($jefa->sede, 2026)->id);
        $this->assertCount(14, app(NagerFestivos::class)->delAnio($jefa->sede, 2026));
    }

    public function test_un_404_indica_que_no_hay_datos_sin_afirmar_que_no_se_publicaron(): void
    {
        $this->fuentes(['https://festivos.io/v1/2026/municipio/28079.json' => Http::response([], 404)]);
        $jefa = User::factory()->jefa()->create();

        $consulta = app(CalendarioMunicipal::class)->cargar($jefa->sede, 2026, '28079', $jefa);

        $this->assertSame('no_disponible', $consulta->estado);
        $this->assertStringContainsString('en el proveedor', $consulta->mensaje);
        $this->assertFalse($consulta->estaActivo());
    }

    public function test_error_500_no_se_confunde_con_sin_cambios_y_conserva_lo_activo(): void
    {
        $this->fuentes(['https://festivos.io/v1/2026/municipio/28079.json' => Http::sequence()->push($this->fixtureCalendario('madrid-2026'))->push([], 500)]);
        $jefa = User::factory()->jefa()->create();
        $servicio = app(CalendarioMunicipal::class);
        $activo = $servicio->cargar($jefa->sede, 2026, '28079', $jefa);

        $consulta = $servicio->cargar($jefa->sede, 2026, '28079', $jefa);

        $this->assertSame('error', $consulta->estado);
        $this->assertStringContainsString('HTTP 500', $consulta->mensaje);
        $this->assertSame($activo->id, $servicio->activo($jefa->sede, 2026)->id);
    }

    public function test_un_timeout_se_registra_como_error_tecnico(): void
    {
        $this->fuentes(['https://festivos.io/v1/2026/municipio/28079.json' => Http::failedConnection()]);
        $jefa = User::factory()->jefa()->create();

        $consulta = app(CalendarioMunicipal::class)->cargar($jefa->sede, 2026, '28079', $jefa);

        $this->assertSame('error', $consulta->estado);
        $this->assertStringContainsString('conectar', $consulta->mensaje);
        $this->assertFalse($consulta->estaActivo());
    }

    public function test_fecha_imposible_en_respuesta_no_se_guarda_como_calendario_activo(): void
    {
        $datos = $this->fixtureCalendario('madrid-2026');
        $datos['holidays'][0]['date'] = '2026-02-30';
        $this->fuentes(['https://festivos.io/v1/2026/municipio/28079.json' => Http::response($datos)]);
        $jefa = User::factory()->jefa()->create();

        $consulta = app(CalendarioMunicipal::class)->cargar($jefa->sede, 2026, '28079', $jefa);

        $this->assertSame('error', $consulta->estado);
        $this->assertSame([], $consulta->festivos);
        $this->assertNull(app(CalendarioMunicipal::class)->activo($jefa->sede, 2026));
    }

    public function test_datos_de_otro_municipio_no_se_aplican_al_solicitado(): void
    {
        $this->fuentes(['https://festivos.io/v1/2026/municipio/28079.json' => Http::response($this->fixtureCalendario('segovia-2026'))]);
        $jefa = User::factory()->jefa()->create();

        $consulta = app(CalendarioMunicipal::class)->cargar($jefa->sede, 2026, '28079', $jefa);

        $this->assertSame('error', $consulta->estado);
        $this->assertFalse($consulta->estaActivo());
    }

    public function test_una_url_no_oficial_del_proveedor_no_se_acepta_como_referencia(): void
    {
        $datos = $this->fixtureCalendario('madrid-2026');
        $datos['holidays'][0]['source']['url'] = 'javascript:alert(1)';
        $this->fuentes(['https://festivos.io/v1/2026/municipio/28079.json' => Http::response($datos)]);
        $jefa = User::factory()->jefa()->create();

        $consulta = app(CalendarioMunicipal::class)->cargar($jefa->sede, 2026, '28079', $jefa);

        $this->assertSame('error', $consulta->estado);
        $this->assertFalse($consulta->estaActivo());
    }

    public function test_discrepancia_oficial_no_sustituye_el_calendario(): void
    {
        $datos = $this->fixtureCalendario('cam-locales-madrid-2026');
        $datos['data'][0]['fecha_festivo'] = '2026-05-16';
        $this->fuentes([CalendarioMunicipal::CAM_LOCALES => Http::response($datos)]);
        $jefa = User::factory()->jefa()->create();

        $consulta = app(CalendarioMunicipal::class)->cargar($jefa->sede, 2026, '28079', $jefa);

        $this->assertSame('conflicto', $consulta->estado);
        $this->assertFalse($consulta->puedeConfirmarse());
        $this->assertFalse($consulta->estaActivo());
    }

    public function test_fuera_de_madrid_hay_revision_explicita_y_confirmacion_trazada(): void
    {
        $this->fuentes(['https://www.boe.es/diario_boe/xml.php?id=BOE-A-2025-21667' => Http::response([], 503)]);
        $jefa = User::factory()->jefa()->create();
        $servicio = app(CalendarioMunicipal::class);
        $consulta = $servicio->cargar($jefa->sede, 2026, '40194', $jefa);

        $confirmado = $servicio->confirmar($consulta, $jefa);

        $this->assertSame('pendiente_revision', $consulta->estado);
        $this->assertFalse($consulta->estaActivo());
        $this->assertSame('cargado', $confirmado->estado);
        $this->assertTrue($confirmado->estaActivo());
        $this->assertSame($jefa->id, $confirmado->activado_por);
        $fechas = app(NagerFestivos::class)->delAnio($jefa->sede, 2026)->map(fn ($dia) => $dia->holiday_date->toDateString())->all();
        $this->assertContains('2026-06-29', $fechas);
        $this->assertNotContains('2026-05-15', $fechas);
    }

    public function test_un_borrador_anterior_no_puede_activarse_sobre_una_consulta_mas_reciente(): void
    {
        $this->fuentes(['https://www.boe.es/diario_boe/xml.php?id=BOE-A-2025-21667' => Http::response([], 503)]);
        $jefa = User::factory()->jefa()->create();
        $servicio = app(CalendarioMunicipal::class);
        $anterior = $servicio->cargar($jefa->sede, 2026, '40194', $jefa);
        $servicio->cargar($jefa->sede, 2026, '40194', $jefa);

        $this->expectException(InvalidArgumentException::class);
        $servicio->confirmar($anterior, $jefa);
    }

    public function test_ajustes_manuales_se_conservan_sin_duplicar_fechas_ni_contagiar_otro_centro(): void
    {
        $this->fuentes();
        $jefa = User::factory()->jefa()->create();
        Festivo::create(['holiday_date' => '2026-01-01', 'name' => 'Nombre revisado internamente', 'ambito' => 'pueblo', 'municipality' => 'Madrid', 'centro_id' => $jefa->centro_id]);
        Festivo::create(['holiday_date' => '2026-11-13', 'name' => 'Cierre del centro', 'ambito' => 'pueblo', 'municipality' => 'Madrid', 'centro_id' => $jefa->centro_id]);

        app(CalendarioMunicipal::class)->cargar($jefa->sede, 2026, '28079', $jefa);

        $dias = app(NagerFestivos::class)->delAnio($jefa->sede, 2026);
        $this->assertCount(15, $dias);
        $this->assertSame('Nombre revisado internamente', $dias->first()->name);
        $this->assertSame(2, Festivo::query()->count());
        $barcelona = Centro::query()->where('name', 'Barcelona')->firstOrFail();
        $this->assertCount(0, app(NagerFestivos::class)->delAnio($barcelona, 2026));
        $this->assertFalse(app(NagerFestivos::class)->coberturaConfirmada($barcelona, 2026));
    }

    public function test_catalogo_invalido_no_sustituye_la_base_local_y_la_carga_sigue_funcionando(): void
    {
        $this->fuentes([CatalogoMunicipios::URL => Http::response(['municipalities' => []])]);
        $jefa = User::factory()->jefa()->create();
        $servicio = app(CalendarioMunicipal::class);
        $catalogo = app(CatalogoMunicipios::class);
        $anterior = $catalogo->estado();

        $error = null;
        try {
            $catalogo->actualizar();
        } catch (RuntimeException $fallo) {
            $error = $fallo;
        }
        $correcta = $servicio->cargar($jefa->sede, 2026, '28079', $jefa);

        $this->assertInstanceOf(RuntimeException::class, $error);
        $this->assertSame($anterior, $catalogo->estado());
        $this->assertDatabaseCount('municipios', 8132);
        $this->assertSame('cargado', $correcta->estado);
        Http::assertSentCount(4);
    }

    public function test_cambiar_territorio_invalida_los_dos_anios_y_conserva_el_historial(): void
    {
        $this->fuentes();
        $jefa = User::factory()->jefa()->create();
        $servicio = app(CalendarioMunicipal::class);
        $original = $servicio->cargar($jefa->sede, 2026, '28079', $jefa);
        $this->calendarioConfirmado($jefa->sede, 2027);

        $servicio->invalidarCentro($jefa->sede, $jefa);

        $this->assertFalse($original->fresh()->estaActivo());
        $this->assertSame(0, ImportacionCalendario::query()->whereNotNull('clave_activa')->count());
        $this->assertSame(4, ImportacionCalendario::query()->count());
        foreach ([2026, 2027] as $anio) {
            $ultima = $servicio->ultima($jefa->sede, $anio);
            $this->assertSame('configuracion_modificada', $ultima->estado);
            $this->assertSame('Pendiente de cargar', $ultima->etiqueta());
            $this->assertFalse($ultima->puedeConfirmarse());
            $this->assertFalse(app(NagerFestivos::class)->coberturaConfirmada($jefa->sede, $anio));
        }
    }

    public function test_cambiar_territorio_tambien_invalida_un_candidato_aun_no_activado(): void
    {
        $this->fuentes(['https://www.boe.es/diario_boe/xml.php?id=BOE-A-2025-21667' => Http::response([], 503)]);
        $jefa = User::factory()->jefa()->create();
        $servicio = app(CalendarioMunicipal::class);
        $candidato = $servicio->cargar($jefa->sede, 2026, '40194', $jefa);
        $servicio->invalidarCentro($jefa->sede, $jefa);

        $this->expectException(InvalidArgumentException::class);
        $servicio->confirmar($candidato, $jefa);
    }

    public function test_el_municipio_exacto_aparece_antes_que_los_otros_de_su_provincia(): void
    {
        $resultados = app(CalendarioMunicipal::class)->municipios('Segovia');

        $this->assertCount(26, $resultados);
        $this->assertSame('40194', $resultados[0]['ine']);
        Http::assertNothingSent();
    }

    public function test_editar_el_territorio_durante_la_descarga_no_reactiva_el_calendario_antiguo(): void
    {
        $jefa = User::factory()->jefa()->create();
        $this->fuentes(['https://festivos.io/v1/2026/municipio/28079.json' => function () use ($jefa) {
            $centro = $jefa->sede->fresh();
            $centro->update(['municipality' => 'Barcelona', 'community' => 'Cataluña']);
            app(CalendarioMunicipal::class)->invalidarCentro($centro, $jefa);

            return Http::response($this->fixtureCalendario('madrid-2026'));
        }]);

        $resultado = app(CalendarioMunicipal::class)->cargar($jefa->sede, 2026, '28079', $jefa);

        $this->assertSame('configuracion_modificada', $resultado->estado);
        $this->assertFalse($resultado->estaActivo());
        $this->assertFalse(app(NagerFestivos::class)->coberturaConfirmada($jefa->sede, 2026));
    }

    public function test_editar_el_territorio_durante_la_descarga_no_deja_un_candidato_confirmable(): void
    {
        $jefa = User::factory()->jefa()->create();
        $this->fuentes(['https://festivos.io/v1/2026/municipio/40194.json' => function () use ($jefa) {
            $centro = $jefa->sede->fresh();
            $centro->update(['municipality' => 'Barcelona', 'community' => 'Cataluña']);
            app(CalendarioMunicipal::class)->invalidarCentro($centro, $jefa);

            return Http::response($this->fixtureCalendario('segovia-2026'));
        }, 'https://www.boe.es/diario_boe/xml.php?id=BOE-A-2025-21667' => Http::response([], 503)]);
        $servicio = app(CalendarioMunicipal::class);

        $resultado = $servicio->cargar($jefa->sede, 2026, '40194', $jefa);

        $this->assertSame('configuracion_modificada', $resultado->estado);
        $this->assertFalse($resultado->puedeConfirmarse());
        $this->expectException(InvalidArgumentException::class);
        $servicio->confirmar($resultado, $jefa);
    }

    public function test_las_fuentes_oficiales_catalana_murciana_y_manchega_permiten_revisar_sus_datos(): void
    {
        $this->fuentes([
            'https://festivos.io/v1/2026/municipio/08019.json' => Http::response($this->fixtureCalendario('barcelona-2026')),
            'https://festivos.io/v1/2026/municipio/30030.json' => Http::response($this->fixtureCalendario('murcia-2026')),
            'https://festivos.io/v1/2026/municipio/02003.json' => Http::response($this->fixtureCalendario('albacete-2026')),
        ]);
        $jefa = User::factory()->jefa()->create();
        $servicio = app(CalendarioMunicipal::class);

        foreach (['08019', '30030', '02003'] as $ine) {
            $resultado = $servicio->cargar($jefa->sede, 2026, $ine, $jefa);

            $this->assertSame('pendiente_revision', $resultado->estado, $resultado->mensaje);
            $this->assertTrue($resultado->puedeConfirmarse());
            $this->assertFalse($resultado->estaActivo());
        }
        Http::assertSentCount(3);
    }

    /** @return array<string,mixed> */
    private function fixtureCalendario(string $nombre): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/Calendario/'.$nombre.'.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $respuestas */
    private function fuentes(array $respuestas = []): void
    {
        Http::preventStrayRequests();
        Http::fake($respuestas + [
            'https://festivos.io/v1/2026/municipio/28079.json' => Http::response($this->fixtureCalendario('madrid-2026')),
            'https://festivos.io/v1/2027/municipio/28079.json' => Http::response($this->fixtureCalendario('madrid-2027')),
            'https://festivos.io/v1/2026/municipio/40194.json' => Http::response($this->fixtureCalendario('segovia-2026')),
            CalendarioMunicipal::CAM_REGIONALES => Http::response($this->fixtureCalendario('cam-regionales-2026')),
            CalendarioMunicipal::CAM_LOCALES => Http::response($this->fixtureCalendario('cam-locales-madrid-2026')),
        ]);
    }
}
