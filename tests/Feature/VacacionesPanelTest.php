<?php

namespace Tests\Feature;

use App\Models\AjusteSaldoVacaciones;
use App\Models\Ausencia;
use App\Models\ImportacionCalendario;
use App\Models\SolicitudVacaciones;
use App\Models\User;
use App\Services\AjustarSaldoVacaciones;
use App\Services\CupoVacaciones;
use App\Services\VigenciasCalendario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class VacacionesPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $jefe;

    private User $trabajador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00:00', 'Europe/Madrid'));
        $this->jefe = User::factory()->jefa()->create();
        $this->trabajador = User::factory()->create(['starts_on' => '2026-01-01']);
    }

    public function test_el_panel_de_2027_muestra_los_datos_de_2027_aunque_hoy_sea_2026(): void
    {
        $this->calendarioConfirmado($this->trabajador->sede, 2026);
        $this->calendarioConfirmado($this->trabajador->sede, 2027);
        $this->ausencia('2026-11-02');
        $this->ausencia('2026-11-03');
        $this->ajustar(2026, 20);
        $this->ausencia('2026-12-14');
        $this->ausencia('2027-01-11');
        $this->ausencia('2027-01-12');
        $this->ajustar(2027, 18);
        $this->solicitud('2027-01-13', '2027-01-14', SolicitudVacaciones::PENDIENTE);
        $this->solicitud('2027-02-01', '2027-02-02', SolicitudVacaciones::RECHAZADA);

        $respuesta = $this->actingAs($this->trabajador)->get(route('calendario', ['anio' => 2027, 'mes' => 1]))->assertOk()
            ->assertViewHas('vacaciones', fn (array $panel): bool => $this->cifras($panel) === [20, 0, 2, 2, 16])
            ->assertSee('Vacaciones de 2027')
            ->assertSee('vacaciones-total">16', false)
            ->assertSee('Del 11/01/2027 al 12/01/2027')
            ->assertSee('Del 13/01/2027 al 14/01/2027')
            ->assertSee('Del 01/02/2027 al 02/02/2027')
            ->assertDontSee('Del 02/11/2026 al 03/11/2026');
        $this->assertSame(1, $this->dentroDelFragmento($respuesta, '//*[@data-vacaciones-pedir]'));
        // Sin JavaScript el botón no haría nada: el servidor lo pinta oculto y reloj.js lo muestra.
        $this->assertSame(1, $this->dentroDelFragmento($respuesta, '//button[@data-vacaciones-pedir][@hidden]'));

        $this->get(route('calendario'))->assertOk()
            ->assertViewHas('vacaciones', fn (array $panel): bool => $this->cifras($panel) === [22, 2, 1, 0, 19])
            ->assertSee('Vacaciones de 2026')
            ->assertSee('vacaciones-total">19', false)
            ->assertDontSee('Del 11/01/2027 al 12/01/2027');
    }

    public function test_sin_ajuste_aparece_pendiente_de_confirmar_y_no_se_inventa_el_saldo(): void
    {
        $this->calendarioConfirmado($this->trabajador->sede, 2026);
        $this->ausencia('2026-11-02');

        $this->actingAs($this->trabajador)->get(route('calendario'))->assertOk()
            ->assertViewHas('vacaciones', fn (array $panel): bool => $panel['corresponden'] === null
                && $panel['sinAsignar'] === null && $panel['disfrutados'] === 1)
            ->assertSee('Pendiente de confirmar por el responsable')
            ->assertSee('Saldo sin confirmar')
            ->assertDontSee('vacaciones-total', false)
            ->assertDontSee('data-vacaciones-pedir', false)
            ->assertDontSee('22 días');
    }

    public function test_con_ajuste_las_cinco_cifras_cuadran_entre_si_y_con_disponibles(): void
    {
        $this->calendarioConfirmado($this->trabajador->sede, 2026);
        $this->calendarioConfirmado($this->trabajador->sede, 2027);
        $this->ausencia('2026-10-05');
        $this->ausencia('2026-10-06');
        $this->ausencia('2026-10-07');
        $ajuste = $this->ajustar(2026, 15);
        $this->ausencia('2026-11-10');
        $this->ausencia('2026-12-21');
        $this->solicitud('2026-11-23', '2026-11-27', SolicitudVacaciones::PENDIENTE);
        $this->solicitud('2026-12-30', '2027-01-05', SolicitudVacaciones::PENDIENTE);
        $this->solicitud('2026-11-16', '2026-11-16', SolicitudVacaciones::RECHAZADA);
        $cupo = app(CupoVacaciones::class);

        $panel = $cupo->resumen($this->trabajador, 2026);

        $this->assertSame([18, 3, 2, 7, 6], $this->cifras($panel));
        $this->assertSame($ajuste->saldo_deseado + $ajuste->aprobados_al_ajuste, $panel['corresponden']);
        $this->assertSame($panel['corresponden'], $panel['disfrutados'] + $panel['porDisfrutar'] + $panel['enEspera'] + $panel['sinAsignar']);
        $this->assertSame($cupo->disponibles($this->trabajador, 2026), $panel['sinAsignar']);
        $this->assertSame($cupo->aprobados($this->trabajador, 2026), $panel['disfrutados'] + $panel['porDisfrutar']);
        $this->assertSame($cupo->pendientes($this->trabajador, 2026), $panel['enEspera']);
        $this->assertSame($panel['disfrutados'] + $panel['porDisfrutar'], array_sum(array_column($panel['periodos'], 'dias')));
        $this->assertSame($panel['enEspera'], array_sum(array_column($panel['solicitadas'], 'dias')));
        $this->assertSame(0, $panel['exceso']);
        $this->assertSame(3, $cupo->resumen($this->trabajador, 2027)['enEspera']);

        $this->ausencia('2026-12-22');
        $this->assertSame(18, $cupo->resumen($this->trabajador, 2026)['corresponden']);
        $this->assertSame(5, $cupo->resumen($this->trabajador, 2026)['sinAsignar']);
    }

    public function test_una_solicitud_rechazada_aparece_en_la_lista_del_anio(): void
    {
        $this->calendarioConfirmado($this->trabajador->sede, 2026);
        $this->ajustar(2026, 10);
        $this->actingAs($this->trabajador)->post(route('calendario.vacaciones'), ['desde' => '2026-11-16', 'hasta' => '2026-11-18'])
            ->assertSessionHas('ok');
        $solicitud = SolicitudVacaciones::query()->where('user_id', $this->trabajador->id)->sole();
        $this->actingAs($this->jefe)->post(route('jefe.vacaciones.decidir', $solicitud), ['decision' => 'rechazada'])
            ->assertSessionHas('ok');

        $this->actingAs($this->trabajador)->get(route('calendario'))->assertOk()
            ->assertViewHas('vacaciones', fn (array $panel): bool => count($panel['rechazadas']) === 1 && $panel['enEspera'] === 0)
            ->assertSee('Rechazadas')
            ->assertSee('Del 16/11/2026 al 18/11/2026')
            ->assertSee('Decidida el 10/11/2026')
            ->assertSee('vacaciones-total">10', false);
        $this->get(route('calendario', ['anio' => 2027]))->assertOk()->assertDontSee('Del 16/11/2026 al 18/11/2026');
    }

    public function test_el_fragmento_que_recarga_el_calendario_incluye_el_panel(): void
    {
        $this->calendarioConfirmado($this->trabajador->sede, 2026);
        $this->ajustar(2026, 5);

        foreach (['mes', 'anio'] as $vista) {
            $this->actingAs($this->trabajador)->get(route('calendario', ['anio' => 2026, 'vista' => $vista, 'fragmento' => 1]))->assertOk()
                ->assertSee('data-vacaciones-panel', false)
                ->assertSee('Vacaciones de 2026')
                ->assertSee('Pedir vacaciones')
                ->assertDontSee('data-vacaciones-barra', false)
                ->assertDontSee('<html', false);
            $pagina = $this->get(route('calendario', ['anio' => 2026, 'vista' => $vista]))->assertOk();
            $this->assertSame(1, $this->dentroDelFragmento($pagina, '//*[@data-vacaciones-panel]'));
            $this->assertSame(1, $this->contar($pagina, '//*[@data-vacaciones-barra][@hidden]'));
            $this->assertSame(1, $this->contar($pagina, '//form[@data-vacaciones-form]//input[@name="_token"]'));
        }
    }

    public function test_el_boton_solo_aparece_si_el_ano_esta_en_plazo_y_quedan_dias(): void
    {
        $this->calendarioConfirmado($this->trabajador->sede, 2026);
        $this->ajustar(2026, 1);

        $this->actingAs($this->trabajador)->get(route('calendario', ['anio' => 2028]))->assertOk()
            ->assertDontSee('data-vacaciones-pedir', false)
            ->assertSee('Solo se pueden pedir fechas de los próximos dos meses.');
        $this->get(route('calendario', ['anio' => 2025]))->assertOk()->assertDontSee('data-vacaciones-pedir', false);

        $this->solicitud('2026-11-20', '2026-11-20', SolicitudVacaciones::PENDIENTE);
        $this->get(route('calendario'))->assertOk()
            ->assertSee('vacaciones-total">0', false)
            ->assertDontSee('data-vacaciones-pedir', false)
            ->assertSee('No te quedan días sin asignar en 2026.');
    }

    public function test_los_periodos_aprobados_se_agrupan_saltando_fines_de_semana_y_festivos(): void
    {
        $importacion = ImportacionCalendario::create([
            'centro_id' => $this->trabajador->centro_id, 'anio' => 2026, 'municipio_ine' => '28079', 'municipio' => 'Madrid',
            'provincia' => 'Madrid', 'comunidad' => 'Comunidad de Madrid', 'comunidad_codigo' => 'ES-MD', 'estado' => 'cargado',
            'mensaje' => 'Calendario de prueba', 'festivos' => [['date' => '2026-12-08', 'name' => 'Inmaculada Concepción', 'ambito' => 'estado']],
            'fuentes' => [], 'vigente_desde' => '2026-01-01', 'consultado_at' => now(), 'activado_at' => now(),
        ]);
        app(VigenciasCalendario::class)->activar($importacion);
        foreach (['2026-11-02', '2026-11-03', '2026-11-09', '2026-11-10', '2026-11-11', '2026-12-04', '2026-12-07', '2026-12-08', '2026-12-09', '2026-12-11'] as $fecha) {
            $this->ausencia($fecha);
        }

        $periodos = collect(app(CupoVacaciones::class)->resumen($this->trabajador, 2026)['periodos'])
            ->map(fn (array $periodo): string => $periodo['desde']->format('d/m').'-'.$periodo['hasta']->format('d/m').':'.$periodo['dias'].':'.$periodo['estado'])
            ->all();

        $this->assertSame([
            '02/11-03/11:2:disfrutado',
            '09/11-11/11:3:en-curso',
            '04/12-09/12:3:por-disfrutar',
            '11/12-11/12:1:por-disfrutar',
        ], $periodos);
        $this->actingAs($this->trabajador)->get(route('calendario'))->assertOk()
            ->assertSee('Del 04/12/2026 al 09/12/2026')
            ->assertSee('El 11/12/2026')
            ->assertSee('3 días · Por disfrutar');
    }

    public function test_cada_persona_ve_solo_sus_vacaciones(): void
    {
        $this->calendarioConfirmado($this->trabajador->sede, 2026);
        $otro = User::factory()->create(['starts_on' => '2026-01-01']);
        $this->ajustar(2026, 12);
        $this->ausencia('2026-11-02');
        $this->solicitud('2026-11-23', '2026-11-24', SolicitudVacaciones::RECHAZADA);

        $this->actingAs($otro)->get(route('calendario', ['user_id' => $this->trabajador->id, 'persona' => $this->trabajador->id]))->assertOk()
            ->assertViewHas('vacaciones', fn (array $panel): bool => $panel['corresponden'] === null
                && $panel['disfrutados'] === 0 && $panel['periodos'] === [] && $panel['rechazadas'] === [])
            ->assertDontSee('Del 23/11/2026 al 24/11/2026')
            ->assertDontSee('El 02/11/2026')
            ->assertSee('Pendiente de confirmar por el responsable');
        $this->actingAs($this->trabajador)->get(route('calendario'))->assertOk()
            ->assertSee('Del 23/11/2026 al 24/11/2026')
            ->assertSee('El 02/11/2026');
    }

    public function test_un_anio_fuera_de_plantilla_no_pide_confirmar_saldo(): void
    {
        $this->calendarioConfirmado($this->trabajador->sede, 2026);
        $this->ajustar(2026, 12);

        $this->actingAs($this->trabajador)->get(route('calendario', ['anio' => 2024]))->assertOk()
            ->assertViewHas('vacaciones', fn (array $panel): bool => $panel['fueraDePlantilla'] === true
                && $panel['corresponden'] === null && $panel['sinAsignar'] === null)
            ->assertSee('Vacaciones de 2024')
            ->assertSee('No estabas en plantilla en 2024.')
            ->assertDontSee('Pendiente de confirmar por el responsable')
            ->assertDontSee('Saldo sin confirmar')
            ->assertDontSee('Te corresponden')
            ->assertDontSee('data-vacaciones-pedir', false);
        $this->get(route('calendario'))->assertOk()
            ->assertViewHas('vacaciones', fn (array $panel): bool => $panel['fueraDePlantilla'] === false && $panel['corresponden'] === 12)
            ->assertDontSee('en plantilla en 2026');

        $this->trabajador->update(['ends_on' => '2026-12-31']);
        $this->get(route('calendario', ['anio' => 2027]))->assertOk()
            ->assertSee('No estarás en plantilla en 2027.')
            ->assertDontSee('Pendiente de confirmar por el responsable');

        $nuevo = User::factory()->create(['starts_on' => '2027-03-01']);
        $this->actingAs($nuevo)->get(route('calendario'))->assertOk()
            ->assertSee('No estás en plantilla en 2026.')
            ->assertDontSee('Saldo sin confirmar');
        $this->get(route('calendario', ['anio' => 2027]))->assertOk()
            ->assertViewHas('vacaciones', fn (array $panel): bool => $panel['fueraDePlantilla'] === false)
            ->assertSee('Pendiente de confirmar por el responsable');
    }

    /** @param array<string, mixed> $panel  @return list<int|null> */
    private function cifras(array $panel): array
    {
        return [$panel['corresponden'], $panel['disfrutados'], $panel['porDisfrutar'], $panel['enEspera'], $panel['sinAsignar']];
    }

    private function ajustar(int $anio, int $saldo): AjusteSaldoVacaciones
    {
        $ultimo = (int) (AjusteSaldoVacaciones::where('user_id', $this->trabajador->id)->where('anio', $anio)->max('id') ?? 0);
        $aprobados = app(CupoVacaciones::class)->aprobados($this->trabajador, $anio);

        return app(AjustarSaldoVacaciones::class)->ajustar($this->jefe, $this->trabajador, $anio, $saldo, $aprobados, $ultimo, 'Saldo de la prueba', (string) Str::uuid());
    }

    private function ausencia(string $fecha): void
    {
        Ausencia::create(['user_id' => $this->trabajador->id, 'absence_date' => $fecha, 'tipo' => 'vacaciones']);
    }

    private function solicitud(string $desde, string $hasta, string $estado): SolicitudVacaciones
    {
        return SolicitudVacaciones::create([
            'user_id' => $this->trabajador->id, 'starts_on' => $desde, 'ends_on' => $hasta, 'estado' => $estado,
            'decidida_por' => $estado === SolicitudVacaciones::PENDIENTE ? null : $this->jefe->id,
            'decidida_at' => $estado === SolicitudVacaciones::PENDIENTE ? null : now(),
        ]);
    }

    private function dentroDelFragmento(TestResponse $respuesta, string $consulta): int
    {
        return $this->contar($respuesta, '//*[@data-calendario]'.$consulta);
    }

    private function contar(TestResponse $respuesta, string $consulta): int
    {
        $documento = new \DOMDocument;
        $documento->loadHTML('<?xml encoding="UTF-8">'.$respuesta->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);

        return (new \DOMXPath($documento))->query($consulta)->length;
    }
}
