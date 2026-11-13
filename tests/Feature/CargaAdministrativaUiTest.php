<?php

namespace Tests\Feature;

use App\Models\AjusteSaldoVacaciones;
use App\Models\CargaAdministrativaDia;
use App\Models\Centro;
use App\Models\Horario;
use App\Models\Jornada;
use App\Models\SolicitudVacaciones;
use App\Models\Tramo;
use App\Models\User;
use App\Services\CalendarioMadridOficial;
use App\Services\CargaAdministrativa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class CargaAdministrativaUiTest extends TestCase
{
    use RefreshDatabase;

    private User $it;

    private User $jefe;

    private User $persona;

    private array $horario = ['morning_start' => '09:00', 'morning_end' => '14:00', 'afternoon_start' => '15:00', 'afternoon_end' => '18:00'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-06 10:00:00', 'Europe/Madrid'));
        $centro = Centro::updateOrCreate(['name' => 'Madrid'], ['municipality' => 'Madrid', 'community' => 'Comunidad de Madrid',
            'municipio_ine' => '28079', 'comunidad_codigo' => 'ES-MD', 'province' => 'Madrid', 'province_code' => '28']);
        $this->it = User::factory()->soloTecnico()->create();
        $this->jefe = User::factory()->jefa()->create(['centro_id' => $centro->id]);
        $this->persona = User::factory()->create(['centro_id' => $centro->id, 'starts_on' => '2026-01-01', 'ends_on' => null]);
        Horario::create(['user_id' => $this->persona->id, 'effective_from' => '2026-01-01', 'tipo' => 'partida', ...$this->horario]);
        app(CalendarioMadridOficial::class)->preparar($centro, $this->it, '2026-01-01', 2027);
        AjusteSaldoVacaciones::create(['user_id' => $this->persona->id, 'actor_id' => $this->jefe->id, 'anio' => 2026,
            'saldo_deseado' => 22, 'aprobados_al_ajuste' => 0, 'motivo' => 'Saldo de prueba', 'operacion' => (string) Str::uuid(), 'created_at' => now()]);
    }

    public function test_el_fichaje_real_sustituye_la_prevision_y_la_semana_no_suma_dos_veces(): void
    {
        $this->cargar('2026-11-05', '2026-11-10');
        $this->actingAs($this->persona)->get(route('jornada'))->assertOk()
            ->assertSee('Total combinado')->assertSee('Fichajes reales')->assertSee('Previsión administrativa');
        $this->post(route('jornada.entrada'), ['modo' => 'ahora'])->assertSessionHas('ok');
        $dia = CargaAdministrativaDia::query()->where('user_id', $this->persona->id)->whereDate('fecha', '2026-11-06')->sole();
        $this->assertSame('sustituida', $dia->estado);
        $this->assertDatabaseCount('jornadas', 1);
        $this->travel(30)->minutes();
        $this->get(route('jornada'))->assertOk()->assertSee('8 h 30')->assertSee('Fichajes reales');
    }

    public function test_registro_y_csv_distinguen_origen_horario_y_filtros(): void
    {
        $this->cargar('2026-11-05', '2026-11-10');
        $query = ['desde' => '2026-11-01', 'hasta' => '2026-11-30'];
        $this->actingAs($this->persona)->get(route('registro', $query))->assertOk()
            ->assertSee('Carga administrativa')->assertSee('Previsión administrativa')->assertSee('09:00–14:00');
        $historicas = $this->get(route('registro', [...$query, 'estado' => 'carga']))->assertOk()->viewData('jornadas');
        $this->assertCount(1, $historicas);
        $this->assertSame('historica', $historicas->first()['registro']->clase);
        $futuras = $this->get(route('registro', [...$query, 'estado' => 'prevision']))->assertOk()->viewData('jornadas');
        $this->assertCount(2, $futuras);
        $this->assertSame('prevision', $futuras->first()['registro']->clase);
        $csv = $this->get(route('registro.csv', $query))->assertOk()->streamedContent();
        $this->assertStringContainsString('Origen', $csv);
        $this->assertStringContainsString('Horario estimado', $csv);
        $this->assertStringContainsString('Carga administrativa', $csv);
        $this->assertStringContainsString('Previsión administrativa', $csv);
        $this->assertStringContainsString('09:00–14:00', $csv);
    }

    public function test_lista_pagina_sesenta_filas_y_preserva_filtros(): void
    {
        $this->cargar('2026-01-01', '2026-04-30');
        $query = ['desde' => '2026-01-01', 'hasta' => '2026-04-30', 'estado' => 'carga'];
        $pagina1 = $this->actingAs($this->persona)->get(route('registro', $query))->assertOk()->viewData('jornadas');
        $this->assertSame(60, $pagina1->count());
        $this->assertGreaterThan(60, $pagina1->total());
        $this->assertStringContainsString('estado=carga', $pagina1->nextPageUrl());
        $pagina2 = $this->get(route('registro', [...$query, 'page' => 2]))->assertOk()->viewData('jornadas');
        $this->assertSame(2, $pagina2->currentPage());
        $this->assertGreaterThan(0, $pagina2->count());
    }

    public function test_al_pasar_la_fecha_la_prevision_se_muestra_como_carga_sin_cambiar_su_origen(): void
    {
        $this->cargar('2026-11-05', '2026-11-10');
        $query = ['desde' => '2026-11-01', 'hasta' => '2026-11-30'];
        $this->travelTo(Carbon::parse('2026-11-10 10:00:00', 'Europe/Madrid'));
        $resumen = app(CargaAdministrativa::class)->resumen($this->persona, $query['desde'], $query['hasta']);
        $this->assertSame(960, $resumen['minutos_historicos']);
        $this->assertSame(480, $resumen['minutos_prevision']);
        $previsiones = $this->actingAs($this->persona)->get(route('registro', [...$query, 'estado' => 'prevision']))
            ->assertOk()->viewData('jornadas');
        $this->assertCount(1, $previsiones);
        $this->assertSame('2026-11-10', $previsiones->first()['registro']->fecha->toDateString());
        $this->assertStringContainsString('Previsión administrativa', $this->get(route('registro.csv', $query))->assertOk()->streamedContent());

        $this->travelTo(Carbon::parse('2026-11-11 10:00:00', 'Europe/Madrid'));
        $resumen = app(CargaAdministrativa::class)->resumen($this->persona, $query['desde'], $query['hasta']);
        $this->assertSame(1440, $resumen['minutos_historicos']);
        $this->assertSame(0, $resumen['minutos_prevision']);
        $historicas = $this->get(route('registro', [...$query, 'estado' => 'carga']))->assertOk()->viewData('jornadas');
        $this->assertCount(3, $historicas);
        $this->assertSame('2026-11-10', $historicas->first()['registro']->fecha->toDateString());
        $this->assertSame('prevision', $historicas->first()['registro']->clase);
        $this->assertCount(0, $this->get(route('registro', [...$query, 'estado' => 'prevision']))->assertOk()->viewData('jornadas'));
        $csv = $this->get(route('registro.csv', $query))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Previsión administrativa', $csv);
        $this->assertStringContainsString('Carga administrativa', $csv);
        $this->assertDatabaseHas('carga_administrativa_dias', ['user_id' => $this->persona->id, 'clase' => 'prevision', 'fecha' => '2026-11-10 00:00:00']);
    }

    public function test_ausencia_manual_y_vacaciones_aprobadas_anulan_solo_su_carga(): void
    {
        $this->cargar('2026-11-05', '2026-11-10');
        $this->actingAs($this->jefe)->post(route('equipo.ausencia', $this->persona), ['absence_date' => '2026-11-06', 'tipo' => 'permiso'])
            ->assertSessionHas('ok');
        $this->assertSame('anulada_ausencia', CargaAdministrativaDia::query()->where('user_id', $this->persona->id)->whereDate('fecha', '2026-11-06')->value('estado'));
        $solicitud = SolicitudVacaciones::create(['user_id' => $this->persona->id, 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-10', 'estado' => 'pendiente']);
        $this->post(route('jefe.vacaciones.decidir', $solicitud), ['decision' => 'aprobada'])->assertSessionHas('ok');
        $this->assertSame('anulada_ausencia', CargaAdministrativaDia::query()->where('user_id', $this->persona->id)->whereDate('fecha', '2026-11-10')->value('estado'));
        $this->assertDatabaseCount('ausencias', 2);
        $this->assertSame(480, app(CargaAdministrativa::class)->resumen($this->persona, '2026-11-01', '2026-11-30')['minutos']);
    }

    public function test_un_conflicto_con_fichaje_real_revierte_ausencia_y_conserva_el_tramo(): void
    {
        $this->cargar('2026-11-05', '2026-11-10');
        $jornada = Jornada::create(['user_id' => $this->persona->id, 'work_date' => '2026-11-06']);
        Tramo::create(['jornada_id' => $jornada->id, 'tipo' => 'trabajo', 'started_at' => '2026-11-06 09:05']);
        $this->actingAs($this->jefe)->post(route('equipo.ausencia', $this->persona), ['absence_date' => '2026-11-06', 'tipo' => 'permiso'])
            ->assertSessionHas('aviso');
        $this->assertDatabaseCount('ausencias', 0);
        $this->assertDatabaseCount('tramos', 1);
        $this->assertSame('vigente', CargaAdministrativaDia::query()->where('user_id', $this->persona->id)->whereDate('fecha', '2026-11-06')->value('estado'));
    }

    private function cargar(string $desde, string $hasta): void
    {
        $servicio = app(CargaAdministrativa::class);
        $vista = $servicio->previsualizar($this->it, [$this->persona->id], $desde, $hasta, $this->horario);
        $servicio->aplicar($this->it, [$this->persona->id], $desde, $hasta, $this->horario, $vista['hash'], 'Carga inicial autorizada');
    }
}
