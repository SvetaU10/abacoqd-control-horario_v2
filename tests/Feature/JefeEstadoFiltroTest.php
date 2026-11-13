<?php

namespace Tests\Feature;

use App\Models\Ausencia;
use App\Models\Aviso;
use App\Models\Horario;
use App\Models\Jornada;
use App\Models\Tramo;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Estado del equipo: cada contador filtra la lista por el estado que calcula
 * JornadaService::estadoDe() y «Ver todos» la vuelve a mostrar entera.
 */
class JefeEstadoFiltroTest extends TestCase
{
    use RefreshDatabase;

    /** Persona que debe aparecer con cada filtro. */
    private const QUIEN_SALE = [
        'trabajando' => 'Ana Trabajando',
        'pausa' => 'Bruno Pausa',
        'pendiente' => 'Carlos Pendiente',
        'ausencia' => 'Diego Ausencia',
        'sin_empezar_hoy' => 'Elena Sin Empezar',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-07 10:45:00', 'Europe/Madrid'));
    }

    public function test_cada_contador_muestra_solo_a_las_personas_en_ese_estado(): void
    {
        $jefe = $this->jefe();
        $this->equipoConUnaPersonaPorEstado();

        foreach (self::QUIEN_SALE as $estado => $nombre) {
            $respuesta = $this->actingAs($jefe)
                ->get(route('jefe.index', ['estado' => $estado]))
                ->assertOk()
                ->assertSee($nombre, false)
                ->assertDontSee('Teo Técnico', false);

            $this->assertSame([$nombre], $this->nombres($respuesta), 'Filtro '.$estado);
            $this->assertSame($estado, $respuesta->viewData('filtroEstado'));
            $this->assertSame(route('jefe.index', ['estado' => $estado]), $this->enlaceActivo($respuesta));
            $this->assertNull($this->verTodos($respuesta), 'Con el filtro '.$estado.' «Ver todos» sigue disponible y sin marcar.');

            foreach (array_diff(self::QUIEN_SALE, [$nombre]) as $otro) {
                $respuesta->assertDontSee($otro, false);
            }

            // Los contadores siguen contando al equipo entero.
            $this->assertSame([
                'trabajando' => 1,
                'pausa' => 1,
                'pendiente' => 1,
                'ausencia' => 1,
                'sin_empezar' => 1,
            ], $respuesta->viewData('cuentas'));
        }
    }

    public function test_sin_filtro_se_ve_todo_el_equipo_y_ver_todos_queda_activo(): void
    {
        $jefe = $this->jefe();
        $this->equipoConUnaPersonaPorEstado();

        $respuesta = $this->actingAs($jefe)
            ->get(route('jefe.index'))
            ->assertOk()
            ->assertSee('Ver todos', false)
            ->assertDontSee('Teo Técnico', false);

        $this->assertNull($respuesta->viewData('filtroEstado'));
        $this->assertSame(route('jefe.index'), $this->enlaceActivo($respuesta));
        $this->assertSame('page', $this->verTodos($respuesta));
        $this->assertEqualsCanonicalizing(
            [...array_values(self::QUIEN_SALE), 'Lola Pérez'],
            $this->nombres($respuesta),
        );

        foreach (array_keys(self::QUIEN_SALE) as $estado) {
            $respuesta->assertSee('href="'.e(route('jefe.index', ['estado' => $estado])).'"', false);
        }
    }

    public function test_un_valor_desconocido_se_ignora_y_se_ve_todo_el_equipo(): void
    {
        $jefe = $this->jefe();
        $this->equipoConUnaPersonaPorEstado();
        $todos = [...array_values(self::QUIEN_SALE), 'Lola Pérez'];

        // «fuera» y «sin_registro» existen en estadoDe() pero no tienen contador.
        foreach (['inventado', 'fuera', 'sin_registro', '', 'TRABAJANDO'] as $valor) {
            $respuesta = $this->actingAs($jefe)
                ->get(route('jefe.index', ['estado' => $valor]))
                ->assertOk()
                ->assertDontSee('Teo Técnico', false);

            $this->assertNull($respuesta->viewData('filtroEstado'), 'Valor '.$valor);
            $this->assertEqualsCanonicalizing($todos, $this->nombres($respuesta));
            $this->assertSame(route('jefe.index'), $this->enlaceActivo($respuesta));
            $this->assertSame('page', $this->verTodos($respuesta));
        }

        $respuesta = $this->actingAs($jefe)
            ->get(route('jefe.index').'?estado[]=pausa')
            ->assertOk();
        $this->assertNull($respuesta->viewData('filtroEstado'));
        $this->assertEqualsCanonicalizing($todos, $this->nombres($respuesta));
    }

    public function test_la_cuenta_tecnica_nunca_aparece_ni_cuenta(): void
    {
        $jefe = $this->jefe();
        $this->equipoConUnaPersonaPorEstado();

        foreach ([null, ...array_keys(self::QUIEN_SALE), 'sin_registro'] as $estado) {
            $respuesta = $this->actingAs($jefe)
                ->get(route('jefe.index', $estado === null ? [] : ['estado' => $estado]))
                ->assertOk()
                ->assertDontSee('Teo Técnico', false);

            $this->assertNotContains('Teo Técnico', $this->nombres($respuesta));
            $this->assertSame(5, array_sum($respuesta->viewData('cuentas')));
        }
    }

    public function test_un_filtro_sin_nadie_lo_dice_y_conserva_el_aviso_del_equipo(): void
    {
        $jefe = $this->jefe();
        $elena = $this->persona('Elena Sin Empezar', 'elena@abaco.test');
        $this->horario($elena);
        Aviso::create(['user_id' => $elena->id, 'tipo' => 'cierre', 'work_date' => '2026-09-20', 'cuenta' => true]);

        $respuesta = $this->actingAs($jefe)
            ->get(route('jefe.index', ['estado' => 'pausa']))
            ->assertOk()
            ->assertSee('Nadie del equipo está ahora en ese estado.', false)
            ->assertSee('1 persona tiene', false)
            ->assertSee('Elena Sin Empezar', false);

        $this->assertSame([], $this->nombres($respuesta));
        $this->assertSame(route('jefe.index', ['estado' => 'pausa']), $this->enlaceActivo($respuesta));
        $this->assertNull($this->verTodos($respuesta));
        $this->assertSame(1, $respuesta->viewData('cuentas')['sin_empezar']);
    }

    public function test_un_trabajador_no_entra_en_el_panel_filtrado(): void
    {
        $trabajador = $this->persona('Ana Trabajando', 'ana@abaco.test');
        $tecnico = User::factory()->soloTecnico()->create(['name' => 'Teo Técnico', 'email' => 'teo@abaco.test']);

        $this->actingAs($trabajador)
            ->get(route('jefe.index', ['estado' => 'trabajando']))
            ->assertRedirect(route('jornada'));
        $this->actingAs($tecnico)
            ->get(route('jefe.index', ['estado' => 'trabajando']))
            ->assertRedirect(route('tecnico.index'));
    }

    /**
     * Una persona en cada estado con contador, el jefe sin horario y una
     * cuenta técnica que no registra jornada.
     */
    private function equipoConUnaPersonaPorEstado(): void
    {
        $ana = $this->persona(self::QUIEN_SALE['trabajando'], 'ana@abaco.test');
        $bruno = $this->persona(self::QUIEN_SALE['pausa'], 'bruno@abaco.test');
        $carlos = $this->persona(self::QUIEN_SALE['pendiente'], 'carlos@abaco.test');
        $diego = $this->persona(self::QUIEN_SALE['ausencia'], 'diego@abaco.test');
        $elena = $this->persona(self::QUIEN_SALE['sin_empezar_hoy'], 'elena@abaco.test');
        foreach ([$ana, $bruno, $carlos, $diego, $elena] as $persona) {
            $this->horario($persona);
        }

        $hoy = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-07']);
        Tramo::create(['jornada_id' => $hoy->id, 'tipo' => Tramo::TRABAJO, 'started_at' => '2026-10-07 09:00:00']);

        $hoy = Jornada::create(['user_id' => $bruno->id, 'work_date' => '2026-10-07']);
        Tramo::create(['jornada_id' => $hoy->id, 'tipo' => Tramo::TRABAJO, 'started_at' => '2026-10-07 09:00:00', 'ended_at' => '2026-10-07 10:30:00']);
        Tramo::create(['jornada_id' => $hoy->id, 'tipo' => Tramo::PAUSA, 'started_at' => '2026-10-07 10:30:00']);

        $ayer = Jornada::create(['user_id' => $carlos->id, 'work_date' => '2026-10-06']);
        Tramo::create(['jornada_id' => $ayer->id, 'tipo' => Tramo::TRABAJO, 'started_at' => '2026-10-06 09:00:00']);

        Ausencia::create(['user_id' => $diego->id, 'absence_date' => '2026-10-07', 'tipo' => 'vacaciones']);

        User::factory()->soloTecnico()->create(['name' => 'Teo Técnico', 'email' => 'teo@abaco.test']);
    }

    /**
     * @return list<string>
     */
    private function nombres(TestResponse $respuesta): array
    {
        return $respuesta->viewData('personas')->pluck('persona.name')->values()->all();
    }

    /** Enlace del panel marcado con aria-current dentro de los contadores. */
    private function enlaceActivo(TestResponse $respuesta): ?string
    {
        $activos = $this->contadores($respuesta, 'a[@aria-current="page"]');

        $this->assertSame(1, $activos->length, 'Debe haber un solo filtro activo.');

        return $activos->item(0)?->getAttribute('href');
    }

    /**
     * Comprueba que los contadores tienen un solo «Ver todos» que lleva al
     * panel sin filtro y devuelve su aria-current (null si no lo lleva).
     * Se busca dentro del nav para no confundirlo con el enlace del menú.
     */
    private function verTodos(TestResponse $respuesta): ?string
    {
        $enlaces = $this->contadores($respuesta, 'a[normalize-space()="Ver todos"]');

        $this->assertSame(1, $enlaces->length, 'Debe haber un solo «Ver todos» en los contadores.');

        $enlace = $enlaces->item(0);
        $this->assertSame(route('jefe.index'), $enlace->getAttribute('href'));

        return $enlace->hasAttribute('aria-current') ? $enlace->getAttribute('aria-current') : null;
    }

    /** @return \DOMNodeList<\DOMElement> */
    private function contadores(TestResponse $respuesta, string $selector): \DOMNodeList
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$respuesta->getContent());

        return (new DOMXPath($dom))->query('//nav[contains(@class, "equipo-contadores")]//'.$selector);
    }

    private function jefe(): User
    {
        return User::factory()->jefa()->create([
            'name' => 'Lola Pérez',
            'email' => 'lola.perez@abaco.test',
        ]);
    }

    private function persona(string $nombre, string $correo): User
    {
        return User::factory()->create(['name' => $nombre, 'email' => $correo]);
    }

    private function horario(User $user): void
    {
        $this->calendarioConfirmado($user->sede, 2026);

        Horario::create([
            'user_id' => $user->id,
            'morning_start' => '09:00',
            'morning_end' => '14:00',
            'afternoon_start' => '15:00',
            'afternoon_end' => '18:00',
            'effective_from' => '2026-01-07',
        ]);
    }
}
