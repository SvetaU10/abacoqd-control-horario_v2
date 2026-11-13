<?php

namespace Tests\Feature;

use App\Models\Centro;
use App\Models\Horario;
use App\Models\User;
use App\TipoJornada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EquipoAltaTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_fecha_imposible_en_la_previsualizacion_no_rompe_el_alta(): void
    {
        $jefa = $this->jefa();

        $this->actingAs($jefa)->get(route('equipo.create', [
            'tipo' => TipoJornada::Partida->value,
            'starts_on' => '2026-99-99',
            'duracion' => 'tres_meses',
        ]))->assertOk()->assertDontSee('99/99/2026', false);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-07 10:45:00', 'Europe/Madrid'));
    }

    public function test_el_alta_muestra_el_calendario_y_pide_el_tipo_antes_de_guardar(): void
    {
        $this->festivosPublicos([
            [
                'date' => '2026-01-01',
                'localName' => 'Año Nuevo',
                'global' => true,
                'counties' => null,
                'types' => ['Public'],
            ],
        ]);
        $marta = $this->jefa();

        $this->actingAs($marta)
            ->get(route('equipo.create'))
            ->assertOk()
            ->assertSee('Calendario 2026', false)
            ->assertSee('Año Nuevo', false)
            ->assertSee('Jornada partida', false)
            ->assertSee('Jornada continua', false)
            ->assertSee('Jornada intensiva', false)
            ->assertSee('Tiempo en la empresa', false)
            ->assertSee('Sede municipal', false)
            ->assertSee('Elige un tipo de jornada para continuar el alta.', false)
            ->assertDontSee('Guardar alta', false);
    }

    public function test_al_elegir_el_tipo_se_pinta_el_calendario_y_sale_el_alta(): void
    {
        $this->festivosPublicos([]);
        $marta = $this->jefa();

        $this->actingAs($marta)
            ->get(route('equipo.create', ['tipo' => TipoJornada::Continua->value]))
            ->assertOk()
            ->assertSee('Jornada continua', false)
            ->assertSee('08:00–15:00', false)
            ->assertSee('Tiempo en la empresa', false)
            ->assertSee('Elige cuánto tiempo estará para verlo en el calendario.', false)
            ->assertDontSee('Guardar alta', false)
            ->assertDontSee('Elige un tipo de jornada para continuar el alta.', false);
    }

    public function test_el_tiempo_en_la_empresa_se_pinta_en_el_calendario(): void
    {
        $this->festivosPublicos([]);
        $marta = $this->jefa();

        $this->actingAs($marta)
            ->get(route('equipo.create', [
                'tipo' => TipoJornada::Partida->value,
                'starts_on' => '2026-10-13',
                'duracion' => 'tres_meses',
            ]))
            ->assertOk()
            ->assertSee('Calendario del periodo', false)
            ->assertSee('octubre 2026', false)
            ->assertSee('enero 2027', false)
            ->assertSee('Laborable · Jornada partida · 3 meses', false)
            ->assertSee('Fuera del tiempo en la empresa', false)
            ->assertSee('casilla-fuera', false)
            ->assertSee('Guardar alta', false)
            ->assertSee('Hasta el 13/01/2027', false)
            ->assertSee('En la empresa desde el 13/10/2026 hasta el 13/01/2027', false)
            ->assertDontSee('Calendario 2026', false)
            ->assertDontSee('marzo', false)
            ->assertDontSee('Año siguiente', false)
            ->assertDontSee('Elige cuánto tiempo estará para verlo en el calendario.', false);
    }

    public function test_ver_en_el_calendario_usa_la_fecha_de_inicio(): void
    {
        $this->festivosPublicos([]);
        $marta = $this->jefa();

        $this->actingAs($marta)
            ->get(route('equipo.create', [
                'tipo' => TipoJornada::Partida->value,
                'starts_on' => '2026-11-20',
                'duracion' => 'tres_meses',
            ]))
            ->assertOk()
            ->assertSee('#alta-calendario', false)
            ->assertSee('Ver en el calendario', false)
            ->assertSee('noviembre 2026', false)
            ->assertSee('febrero 2027', false)
            ->assertSee('Hasta el 20/02/2027', false)
            ->assertDontSee('octubre 2026', false);
    }

    public function test_hasta_un_dia_concreto_pide_la_fecha_antes_de_pintar(): void
    {
        $this->festivosPublicos([]);
        $marta = $this->jefa();

        $this->actingAs($marta)
            ->get(route('equipo.create', [
                'tipo' => TipoJornada::Partida->value,
                'starts_on' => '2026-10-13',
                'duracion' => 'hasta_el_dia',
            ]))
            ->assertOk()
            ->assertSee('Elige el último día para verlo en el calendario.', false)
            ->assertDontSee('Guardar alta', false)
            ->assertDontSee('Elige cuánto tiempo estará para verlo en el calendario.', false);

        $this->actingAs($marta)
            ->get(route('equipo.create', [
                'tipo' => TipoJornada::Partida->value,
                'starts_on' => '2026-10-13',
                'duracion' => 'hasta_el_dia',
                'ends_on' => '2026-12-20',
            ]))
            ->assertOk()
            ->assertSee('Calendario del periodo', false)
            ->assertSee('En la empresa desde el 13/10/2026 hasta el 20/12/2026', false)
            ->assertSee('Guardar alta', false)
            ->assertDontSee('Elige el último día para verlo en el calendario.', false);
    }

    public function test_el_alta_exige_tipo_de_jornada(): void
    {
        $marta = $this->jefa();

        $this->actingAs($marta)
            ->from(route('equipo.create'))
            ->post(route('equipo.store'), [
                'name' => 'Nuria Vega',
                'email' => 'nuria.vega@abaco.test',
                'password' => 'Jornada2026',
                'starts_on' => '2026-10-13',
            ])
            ->assertRedirect(route('equipo.create'))
            ->assertSessionHasErrors('tipo');

        $this->assertNull(User::query()->where('email', 'nuria.vega@abaco.test')->first());
    }

    public function test_el_alta_guarda_el_horario_del_tipo_elegido(): void
    {
        $marta = $this->jefa();

        $this->actingAs($marta)
            ->post(route('equipo.store'), [
                'name' => 'Nuria Vega',
                'email' => 'nuria.vega@abaco.test',
                'password' => 'Jornada2026',
                'starts_on' => '2026-10-13',
                'duracion' => 'indefinido',
                'centro' => 'Madrid',
                'tipo' => TipoJornada::Continua->value,
                'morning_start' => '09:00',
                'morning_end' => '14:00',
                'afternoon_start' => '15:00',
                'afternoon_end' => '18:00',
            ])
            ->assertSessionHas('ok');

        $persona = User::query()->where('email', 'nuria.vega@abaco.test')->first();
        $this->assertNotNull($persona);
        $horario = Horario::query()->where('user_id', $persona->id)->first();
        $this->assertSame(TipoJornada::Continua->value, $horario->tipo);
        $this->assertSame('08:00', substr($horario->morning_start, 0, 5));
        $this->assertSame('15:00', substr($horario->morning_end, 0, 5));
        $this->assertSame('15:00', substr($horario->afternoon_end, 0, 5));
        $this->assertNull($persona->ends_on);
    }

    public function test_el_alta_guarda_el_tiempo_en_la_empresa(): void
    {
        $marta = $this->jefa();

        $this->actingAs($marta)
            ->post(route('equipo.store'), [
                'name' => 'Nuria Vega',
                'email' => 'nuria.vega@abaco.test',
                'password' => 'Jornada2026',
                'starts_on' => '2026-10-13',
                'duracion' => 'tres_meses',
                'centro' => 'Madrid',
                'tipo' => TipoJornada::Partida->value,
            ])
            ->assertSessionHas('ok');

        $persona = User::query()->where('email', 'nuria.vega@abaco.test')->first();
        $this->assertSame('2027-01-13', $persona->ends_on->toDateString());
    }

    public function test_el_alta_exige_el_ultimo_dia_si_el_tiempo_es_concreto(): void
    {
        $marta = $this->jefa();

        $this->actingAs($marta)
            ->from(route('equipo.create', ['tipo' => TipoJornada::Partida->value]))
            ->post(route('equipo.store'), [
                'name' => 'Nuria Vega',
                'email' => 'nuria.vega@abaco.test',
                'password' => 'Jornada2026',
                'starts_on' => '2026-10-13',
                'duracion' => 'hasta_el_dia',
                'centro' => 'Madrid',
                'tipo' => TipoJornada::Partida->value,
            ])
            ->assertSessionHasErrors('ends_on');
    }

    public function test_el_calendario_del_alta_sigue_la_sede_elegida(): void
    {
        $this->festivosPublicos([
            [
                'date' => '2026-04-02',
                'localName' => 'Jueves Santo',
                'global' => false,
                'counties' => ['ES-MD'],
                'types' => ['Public'],
            ],
            [
                'date' => '2026-09-11',
                'localName' => 'Diada Nacional de Catalunya',
                'global' => false,
                'counties' => ['ES-CT'],
                'types' => ['Public'],
            ],
        ]);
        $marta = $this->jefa();

        $this->actingAs($marta)
            ->get(route('equipo.create', [
                'tipo' => TipoJornada::Partida->value,
                'starts_on' => '2026-09-01',
                'duracion' => 'indefinido',
                'centro' => 'Barcelona',
                'anio' => 2026,
            ]))
            ->assertOk()
            ->assertSee('Festivos públicos de Barcelona', false)
            ->assertSee('Diada Nacional de Catalunya', false)
            ->assertDontSee('Jueves Santo', false);
    }

    public function test_el_alta_guarda_la_sede_elegida(): void
    {
        $marta = $this->jefa();

        $this->actingAs($marta)
            ->post(route('equipo.store'), [
                'name' => 'Nuria Vega',
                'email' => 'nuria.vega@abaco.test',
                'password' => 'Jornada2026',
                'starts_on' => '2026-10-13',
                'duracion' => 'indefinido',
                'centro' => 'Barcelona',
                'tipo' => TipoJornada::Partida->value,
            ])
            ->assertSessionHas('ok');

        $persona = User::query()->where('email', 'nuria.vega@abaco.test')->first();
        $this->assertSame('Barcelona', $persona->sede->name);
        $this->assertSame('Barcelona', $persona->municipality);
    }

    public function test_no_se_ficha_despues_del_ultimo_dia(): void
    {
        $ana = User::create([
            'name' => 'Ana López',
            'email' => 'ana.lopez@abaco.test',
            'password' => 'Jornada2026',
            'role' => 'trabajadora',
            'active' => true,
            'starts_on' => '2026-01-07',
            'ends_on' => '2026-10-06',
            'municipality' => 'Madrid',
            'centro_id' => Centro::query()->where('name', 'Madrid')->value('id'),
        ]);
        Horario::create([
            'user_id' => $ana->id,
            'tipo' => TipoJornada::Partida->value,
            'morning_start' => '09:00',
            'morning_end' => '14:00',
            'afternoon_start' => '15:00',
            'afternoon_end' => '18:00',
            'effective_from' => '2026-01-07',
        ]);

        $this->actingAs($ana)
            ->post(route('jornada.entrada'))
            ->assertSessionHas('aviso', 'Tu tiempo en la empresa terminó el martes 6 de octubre.');
    }

    private function jefa(): User
    {
        return User::create([
            'name' => 'Marta Ruiz',
            'email' => 'marta.ruiz@abaco.test',
            'password' => 'Jornada2026',
            'role' => 'jefa',
            'active' => true,
            'starts_on' => '2026-01-07',
            'municipality' => 'Madrid',
            'centro_id' => Centro::query()->where('name', 'Madrid')->value('id'),
        ]);
    }
}
