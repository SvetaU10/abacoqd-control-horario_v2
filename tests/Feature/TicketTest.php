<?php

namespace Tests\Feature;

use App\Mail\TicketNuevo;
use App\Mail\TicketResuelto;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class TicketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_cualquier_rol_puede_abrir_un_ticket_y_avisa_al_tecnico(): void
    {
        $tecnico = User::factory()->tecnico()->create();

        foreach ([User::factory()->create(), User::factory()->jefa()->create()] as $persona) {
            $this->actingAs($persona)->post(route('tickets.store'), [
                'asunto' => 'No me deja fichar',
                'descripcion' => 'Al pulsar Empezar no pasa nada.',
                'desde' => '/jornada',
            ])->assertRedirect();

            $ticket = Ticket::where('user_id', $persona->id)->firstOrFail();
            $this->assertSame(Ticket::ABIERTO, $ticket->estado);
            $this->assertSame('/jornada', $ticket->pagina);
        }

        Mail::assertSent(TicketNuevo::class, 2);
        Mail::assertSent(TicketNuevo::class, fn (TicketNuevo $correo) => $correo->hasTo($tecnico->email));
    }

    public function test_el_tecnico_que_abre_un_ticket_no_se_avisa_a_si_mismo(): void
    {
        $tecnico = User::factory()->tecnico()->create();

        $this->actingAs($tecnico)->post(route('tickets.store'), [
            'asunto' => 'Prueba',
            'descripcion' => 'Ticket de prueba del técnico.',
        ])->assertRedirect();

        Mail::assertNothingSent();
    }

    public function test_si_el_correo_falla_el_ticket_se_guarda(): void
    {
        User::factory()->tecnico()->create();
        $ana = User::factory()->create();
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('sin conexión'));

        $this->actingAs($ana)->post(route('tickets.store'), [
            'asunto' => 'Fallo',
            'descripcion' => 'Algo ha fallado en la pantalla.',
        ])->assertRedirect();

        $this->assertSame(1, Ticket::where('user_id', $ana->id)->count());
    }

    public function test_el_formulario_rechaza_datos_no_validos(): void
    {
        $ana = User::factory()->create();

        $this->actingAs($ana)->get(route('tickets.create'))
            ->assertOk()
            ->assertSee('0 a 500 caracteres', false)
            ->assertDontSee('0/500 caracteres', false);

        $this->actingAs($ana)->post(route('tickets.store'), [
            'asunto' => '',
            'descripcion' => str_repeat('a', 501),
        ])->assertSessionHasErrors(['asunto', 'descripcion']);

        $this->actingAs($ana)->post(route('tickets.store'), [
            'asunto' => "Dos\nlíneas",
            'descripcion' => 'Una descripción suficiente.',
        ])->assertSessionHasErrors('asunto');

        $this->assertSame(0, Ticket::count());
    }

    public function test_la_pagina_de_origen_solo_se_guarda_si_es_de_la_aplicacion(): void
    {
        $ana = User::factory()->create();

        foreach (['https://externa.example/x', '//externa.example/x'] as $externa) {
            $this->actingAs($ana)->post(route('tickets.store'), [
                'asunto' => 'Origen',
                'descripcion' => 'Probando la página de origen.',
                'desde' => $externa,
            ]);
        }

        $this->assertSame(2, Ticket::whereNull('pagina')->count());
    }

    public function test_solo_el_autor_y_el_tecnico_ven_un_ticket(): void
    {
        $ana = User::factory()->create();
        $ticket = Ticket::factory()->create(['user_id' => $ana->id]);

        $this->actingAs($ana)->get(route('tickets.show', $ticket))->assertOk();
        $this->actingAs(User::factory()->tecnico()->create())->get(route('tickets.show', $ticket))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('tickets.show', $ticket))->assertNotFound();
        $this->actingAs(User::factory()->jefa()->create())->get(route('tickets.show', $ticket))->assertNotFound();
        $this->actingAs(User::factory()->create())
            ->post(route('tickets.mensaje', $ticket), ['cuerpo' => 'Hola'])->assertNotFound();
    }

    public function test_cada_persona_solo_ve_sus_tickets_en_ayuda(): void
    {
        $ana = User::factory()->create();
        Ticket::factory()->create(['user_id' => $ana->id, 'asunto' => 'Asunto de Ana']);
        Ticket::factory()->create(['asunto' => 'Asunto de otra persona']);

        $this->actingAs($ana)->get(route('tickets.index'))
            ->assertOk()
            ->assertSee('Asunto de Ana')
            ->assertDontSee('Asunto de otra persona');
    }

    public function test_el_trabajador_filtra_sus_tickets_por_estado_en_ayuda(): void
    {
        $ana = User::factory()->create();
        Ticket::factory()->create(['user_id' => $ana->id, 'asunto' => 'Abierto de Ana']);
        Ticket::factory()->enCurso()->create(['user_id' => $ana->id, 'asunto' => 'En curso de Ana']);
        Ticket::factory()->resuelto()->create(['user_id' => $ana->id, 'asunto' => 'Cerrado de Ana']);

        $this->actingAs($ana)->get(route('tickets.index'))
            ->assertOk()
            ->assertSee('Filtrar por estado')
            ->assertSee('Abierto de Ana')
            ->assertSee('En curso de Ana')
            ->assertSee('Cerrado de Ana');
        $this->actingAs($ana)->get(route('tickets.index', ['estado' => 'abierto']))
            ->assertOk()
            ->assertSee('Abierto de Ana')
            ->assertDontSee('En curso de Ana')
            ->assertDontSee('Cerrado de Ana');
        $this->actingAs($ana)->get(route('tickets.index', ['estado' => 'en_curso']))
            ->assertOk()
            ->assertSee('En curso de Ana')
            ->assertDontSee('Abierto de Ana')
            ->assertDontSee('Cerrado de Ana');
        $this->actingAs($ana)->get(route('tickets.index', ['estado' => 'resuelto']))
            ->assertOk()
            ->assertSee('Cerrado de Ana')
            ->assertDontSee('Abierto de Ana')
            ->assertDontSee('En curso de Ana');
    }

    public function test_el_trabajador_ve_cinco_tickets_en_ayuda_y_puede_abrir_el_resto(): void
    {
        $ana = User::factory()->create();
        Ticket::factory()->count(6)->sequence(
            ['asunto' => 'Incidencia antigua'],
            ['asunto' => 'Incidencia dos'],
            ['asunto' => 'Incidencia tres'],
            ['asunto' => 'Incidencia cuatro'],
            ['asunto' => 'Incidencia cinco'],
            ['asunto' => 'Incidencia reciente'],
        )->create(['user_id' => $ana->id]);

        $this->actingAs($ana)->get(route('tickets.index'))
            ->assertOk()
            ->assertSee('Ver todas las incidencias')
            ->assertSee('Incidencia reciente')
            ->assertDontSee('Incidencia antigua');

        $this->actingAs($ana)->get(route('tickets.index', ['todas_incidencias' => 1]))
            ->assertOk()
            ->assertSee('Ver menos')
            ->assertSee('Incidencia reciente')
            ->assertSee('Incidencia antigua')
            ->assertSee('href="'.route('tickets.index').'"', false);

        $this->actingAs($ana)->get(route('tickets.index'))
            ->assertOk()
            ->assertSee('Ver todas las incidencias')
            ->assertSee('Incidencia reciente')
            ->assertDontSee('Incidencia antigua');
    }

    public function test_la_conversacion_cambia_el_estado(): void
    {
        $ana = User::factory()->create();
        $tecnico = User::factory()->tecnico()->create();
        $ticket = Ticket::factory()->create(['user_id' => $ana->id]);

        $this->actingAs($tecnico)->post(route('tickets.mensaje', $ticket), ['cuerpo' => 'Lo miro ahora.']);
        $this->assertSame(Ticket::EN_CURSO, $ticket->fresh()->estado);

        $this->actingAs($ana)->post(route('tickets.mensaje', $ticket), ['cuerpo' => 'Gracias.']);
        $this->assertSame(Ticket::EN_CURSO, $ticket->fresh()->estado);
        $this->assertSame(2, $ticket->mensajes()->count());

        $this->actingAs($tecnico)->post(route('tecnico.tickets.estado', $ticket), ['estado' => Ticket::RESUELTO]);
        $this->assertTrue($ticket->fresh()->estaResuelto());
        $this->assertNotNull($ticket->fresh()->resuelto_at);

        $this->actingAs($ana)->post(route('tickets.mensaje', $ticket), ['cuerpo' => 'Sigue fallando.']);
        $this->assertSame(Ticket::ABIERTO, $ticket->fresh()->estado);
        $this->assertNull($ticket->fresh()->resuelto_at);
    }

    public function test_el_trabajador_puede_recorrer_mas_de_cien_tickets_sin_perder_el_filtro(): void
    {
        $ana = User::factory()->create();
        $antiguo = Ticket::factory()->resuelto()->create(['user_id' => $ana->id, 'asunto' => 'El ticket más antiguo de Ana']);
        Ticket::factory()->resuelto()->count(100)->create(['user_id' => $ana->id]);
        Ticket::factory()->create(['user_id' => $ana->id, 'asunto' => 'Abierto fuera del filtro']);
        Ticket::factory()->resuelto()->create(['asunto' => 'Ticket de otra persona']);

        $respuesta = $this->actingAs($ana)->get(route('tickets.index', [
            'todas_incidencias' => 1,
            'estado' => Ticket::RESUELTO,
            'desde' => '/registro',
        ]))
            ->assertOk()
            ->assertSee('Siguiente')
            ->assertDontSee($antiguo->asunto)
            ->assertDontSee('Abierto fuera del filtro')
            ->assertDontSee('Ticket de otra persona');

        $paginas = $respuesta->viewData('tickets');
        $this->assertSame(101, $paginas->total());
        $this->assertGreaterThan(1, $paginas->lastPage());

        $segunda = $this->actingAs($ana)->get($paginas->url(2))
            ->assertOk()
            ->assertSee('Anterior')
            ->assertDontSee('Abierto fuera del filtro')
            ->assertDontSee('Ticket de otra persona');
        $this->assertSame(2, $segunda->viewData('tickets')->currentPage());
        $this->assertStringContainsString('estado=resuelto', $segunda->viewData('tickets')->previousPageUrl());
        $this->assertStringContainsString('todas_incidencias=1', $segunda->viewData('tickets')->previousPageUrl());
        $this->assertStringContainsString('desde=%2Fregistro', $segunda->viewData('tickets')->previousPageUrl());

        $this->actingAs($ana)->get($paginas->url($paginas->lastPage()))
            ->assertOk()
            ->assertSee($antiguo->asunto)
            ->assertDontSee('Abierto fuera del filtro')
            ->assertDontSee('Ticket de otra persona');
    }

    public function test_el_tecnico_puede_recorrer_mas_de_cien_tickets_del_trabajador_filtrado(): void
    {
        $tecnico = User::factory()->tecnico()->create();
        $ana = User::factory()->create();
        $lucia = User::factory()->create();
        $antiguo = Ticket::factory()->create(['user_id' => $ana->id, 'asunto' => 'El ticket antiguo del trabajador filtrado']);
        Ticket::factory()->count(100)->create(['user_id' => $ana->id]);
        Ticket::factory()->resuelto()->create(['user_id' => $ana->id, 'asunto' => 'Resuelto fuera del filtro']);
        Ticket::factory()->create(['user_id' => $lucia->id, 'asunto' => 'Abierto de otra trabajadora']);

        $respuesta = $this->actingAs($tecnico)->get(route('tecnico.tickets', [
            'todas_incidencias' => 1,
            'estado' => Ticket::ABIERTO,
            'trabajador' => $ana->id,
        ]))
            ->assertOk()
            ->assertSee('Siguiente')
            ->assertDontSee($antiguo->asunto)
            ->assertDontSee('Resuelto fuera del filtro')
            ->assertDontSee('Abierto de otra trabajadora');

        $paginas = $respuesta->viewData('tickets');
        $this->assertSame(101, $paginas->total());
        $this->assertGreaterThan(1, $paginas->lastPage());

        $segunda = $this->actingAs($tecnico)->get($paginas->url(2))
            ->assertOk()
            ->assertSee('Anterior')
            ->assertDontSee('Resuelto fuera del filtro')
            ->assertDontSee('Abierto de otra trabajadora');
        $this->assertSame(2, $segunda->viewData('tickets')->currentPage());
        $this->assertStringContainsString('estado=abierto', $segunda->viewData('tickets')->previousPageUrl());
        $this->assertStringContainsString('trabajador='.$ana->id, $segunda->viewData('tickets')->previousPageUrl());
        $this->assertStringContainsString('todas_incidencias=1', $segunda->viewData('tickets')->previousPageUrl());

        $this->actingAs($tecnico)->get($paginas->url($paginas->lastPage()))
            ->assertOk()
            ->assertSee($antiguo->asunto)
            ->assertDontSee('Resuelto fuera del filtro')
            ->assertDontSee('Abierto de otra trabajadora');
    }

    public function test_al_resolver_se_avisa_al_autor_una_sola_vez(): void
    {
        $ana = User::factory()->create();
        $tecnico = User::factory()->tecnico()->create();
        $ticket = Ticket::factory()->create(['user_id' => $ana->id]);

        $this->actingAs($tecnico)->post(route('tecnico.tickets.estado', $ticket), ['estado' => Ticket::RESUELTO]);
        $this->actingAs($tecnico)->post(route('tecnico.tickets.estado', $ticket), ['estado' => Ticket::RESUELTO]);

        Mail::assertSent(TicketResuelto::class, 1);
        Mail::assertSent(TicketResuelto::class, fn (TicketResuelto $correo) => $correo->hasTo($ana->email));
    }

    public function test_solo_el_tecnico_cambia_el_estado_y_ve_todos_los_tickets(): void
    {
        $ana = User::factory()->create();
        $ticket = Ticket::factory()->create(['user_id' => $ana->id]);

        $this->actingAs($ana)->post(route('tecnico.tickets.estado', $ticket), ['estado' => Ticket::RESUELTO])
            ->assertRedirect(route('jornada'));
        $this->actingAs($ana)->get(route('tecnico.tickets'))->assertRedirect(route('jornada'));
        $this->actingAs(User::factory()->jefa()->create())->get(route('tecnico.tickets'))
            ->assertRedirect(route('jornada'));
        $this->assertSame(Ticket::ABIERTO, $ticket->fresh()->estado);

        $this->actingAs(User::factory()->tecnico()->create())
            ->get(route('tecnico.tickets'))
            ->assertOk()
            ->assertSee($ticket->asunto);
    }

    public function test_el_tecnico_ve_un_aviso_cuando_hay_un_ticket_nuevo(): void
    {
        $tecnico = User::factory()->tecnico()->create();
        $ana = User::factory()->create();

        $this->actingAs($tecnico)->get(route('jornada'))
            ->assertOk()
            ->assertDontSee('aviso-ticket', false);

        Ticket::factory()->create(['user_id' => $ana->id]);
        Ticket::factory()->enCurso()->create(['user_id' => $ana->id]);
        Ticket::factory()->resuelto()->create(['user_id' => $ana->id]);

        $this->actingAs($tecnico)->get(route('jornada'))
            ->assertSee('aviso-ticket', false)
            ->assertSee('Tickets, 1 nuevo', false);

        $this->actingAs($ana)->get(route('jornada'))
            ->assertDontSee('aviso-ticket', false);

        Ticket::query()->where('estado', Ticket::ABIERTO)->update(['estado' => Ticket::EN_CURSO]);

        $this->actingAs($tecnico)->get(route('jornada'))
            ->assertDontSee('aviso-ticket', false);
    }

    public function test_el_trabajador_ve_un_aviso_en_ayuda_cuando_el_tecnico_responde(): void
    {
        $ana = User::factory()->create();
        $tecnico = User::factory()->tecnico()->create();
        $ticket = Ticket::factory()->create(['user_id' => $ana->id]);
        $otro = Ticket::factory()->create(['user_id' => $ana->id]);

        $this->actingAs($ana)->get(route('jornada'))
            ->assertOk()
            ->assertDontSee('aviso-ticket', false);

        $this->actingAs($tecnico)->post(route('tickets.mensaje', $ticket), ['cuerpo' => 'Lo miro ahora.'])
            ->assertRedirect(route('tickets.show', $ticket));
        $this->actingAs($tecnico)->post(route('tickets.mensaje', $otro), ['cuerpo' => 'También este.'])
            ->assertRedirect(route('tickets.show', $otro));

        $this->actingAs($tecnico)->get(route('tickets.show', $ticket))->assertOk();
        $this->actingAs($tecnico)->get(route('jornada'))
            ->assertDontSee('Ayuda, 2 respuestas nuevas', false);

        $this->actingAs($ana)->get(route('jornada'))
            ->assertOk()
            ->assertSee('aviso-ticket', false)
            ->assertSee('Ayuda, 2 respuestas nuevas', false);

        $this->actingAs($ana)->get(route('tickets.index'))
            ->assertOk()
            ->assertSee('Ayuda, 2 respuestas nuevas', false);

        $this->actingAs($ana)->get(route('tickets.show', $ticket))->assertOk();

        $this->actingAs($ana)->get(route('jornada'))
            ->assertSee('Ayuda, 1 respuesta nueva', false);

        $this->actingAs($ana)->get(route('tickets.show', $otro))->assertOk();

        $this->actingAs($ana)->get(route('jornada'))
            ->assertDontSee('aviso-ticket', false)
            ->assertDontSee('Ayuda, 1 respuesta nueva', false);

        $this->actingAs($ana)->post(route('tickets.mensaje', $ticket), ['cuerpo' => 'Gracias.'])
            ->assertRedirect(route('tickets.show', $ticket));

        $this->actingAs($ana)->get(route('jornada'))
            ->assertDontSee('aviso-ticket', false);

        $this->actingAs($tecnico)->post(route('tickets.mensaje', $ticket), ['cuerpo' => 'Ya está.'])
            ->assertRedirect(route('tickets.show', $ticket));

        $this->actingAs($ana)->get(route('jornada'))
            ->assertSee('Ayuda, 1 respuesta nueva', false);
    }

    public function test_el_tecnico_filtra_los_tickets_por_estado(): void
    {
        $tecnico = User::factory()->tecnico()->create();
        Ticket::factory()->create(['asunto' => 'Abierto uno']);
        Ticket::factory()->enCurso()->create(['asunto' => 'En curso uno']);
        Ticket::factory()->resuelto()->create(['asunto' => 'Cerrado uno']);

        $this->actingAs($tecnico)->get(route('tecnico.tickets'))
            ->assertSee('Abierto uno')
            ->assertDontSee('En curso uno')
            ->assertDontSee('Cerrado uno');
        $this->actingAs($tecnico)->get(route('tecnico.tickets', ['estado' => 'en_curso']))
            ->assertSee('En curso uno')
            ->assertDontSee('Abierto uno')
            ->assertDontSee('Cerrado uno');
        $this->actingAs($tecnico)->get(route('tecnico.tickets', ['estado' => 'resuelto']))
            ->assertSee('Cerrado uno')
            ->assertDontSee('Abierto uno')
            ->assertDontSee('En curso uno');
        $this->actingAs($tecnico)->get(route('tecnico.tickets', ['estado' => 'todos']))
            ->assertSee('Abierto uno')
            ->assertSee('En curso uno')
            ->assertSee('Cerrado uno');
    }

    public function test_el_tecnico_filtra_los_tickets_por_trabajador(): void
    {
        $tecnico = User::factory()->tecnico()->create();
        $ana = User::factory()->create(['name' => 'Ana López']);
        $lucia = User::factory()->create(['name' => 'Lucía Vega']);
        $nuria = User::factory()->create(['name' => 'Nuria Soler']);
        Ticket::factory()->create(['user_id' => $ana->id, 'asunto' => 'Ticket de Ana']);
        Ticket::factory()->resuelto()->create(['user_id' => $ana->id, 'asunto' => 'Cerrado de Ana']);
        Ticket::factory()->create(['user_id' => $lucia->id, 'asunto' => 'Ticket de Lucía']);

        $this->actingAs($tecnico)->get(route('tecnico.tickets'))
            ->assertOk()
            ->assertSee('Filtrar por trabajador')
            ->assertSee('Filtrar por estado')
            ->assertDontSee('>Ver</', false)
            ->assertSee('Ticket de Ana')
            ->assertSee('Ticket de Lucía')
            ->assertDontSee('Cerrado de Ana')
            ->assertSee($nuria->name, false);

        $this->actingAs($tecnico)->get(route('tecnico.tickets', ['trabajador' => $ana->id]))
            ->assertOk()
            ->assertSee('Ticket de Ana')
            ->assertDontSee('Ticket de Lucía')
            ->assertDontSee('Cerrado de Ana')
            ->assertSee('trabajador='.$ana->id, false);

        $this->actingAs($tecnico)->get(route('tecnico.tickets', ['estado' => 'resuelto', 'trabajador' => $ana->id]))
            ->assertOk()
            ->assertSee('Cerrado de Ana')
            ->assertDontSee('Ticket de Ana')
            ->assertDontSee('Ticket de Lucía');

        $this->actingAs($tecnico)->get(route('tecnico.tickets', ['estado' => 'todos', 'trabajador' => $lucia->id]))
            ->assertOk()
            ->assertSee('Ticket de Lucía')
            ->assertDontSee('Ticket de Ana')
            ->assertDontSee('Cerrado de Ana');
    }

    public function test_el_tecnico_ve_cinco_tickets_y_puede_abrir_el_resto(): void
    {
        $tecnico = User::factory()->tecnico()->create();
        Ticket::factory()->count(6)->sequence(
            ['asunto' => 'Incidencia antigua'],
            ['asunto' => 'Incidencia dos'],
            ['asunto' => 'Incidencia tres'],
            ['asunto' => 'Incidencia cuatro'],
            ['asunto' => 'Incidencia cinco'],
            ['asunto' => 'Incidencia reciente'],
        )->create();

        $this->actingAs($tecnico)->get(route('tecnico.tickets'))
            ->assertOk()
            ->assertSee('Ver todas las incidencias')
            ->assertSee('Incidencia reciente')
            ->assertDontSee('Incidencia antigua');

        $this->actingAs($tecnico)->get(route('tecnico.tickets', ['todas_incidencias' => 1]))
            ->assertOk()
            ->assertSee('Ver menos')
            ->assertSee('Incidencia reciente')
            ->assertSee('Incidencia antigua')
            ->assertSee('href="'.route('tecnico.tickets').'"', false);

        $this->actingAs($tecnico)->get(route('tecnico.tickets'))
            ->assertOk()
            ->assertSee('Ver todas las incidencias')
            ->assertSee('Incidencia reciente')
            ->assertDontSee('Incidencia antigua');
    }

    public function test_comunicar_una_incidencia_de_conexion_abre_un_ticket_para_el_tecnico(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-07 10:45:00', 'Europe/Madrid'));
        $ana = User::factory()->create();
        $tecnico = User::factory()->tecnico()->create();

        $this->actingAs($ana)->post(route('jornada.incidencia'), [
            'motivo' => 'Se cayó la red',
        ])->assertSessionHas('ok');

        $this->assertDatabaseCount('tramos', 0);
        $this->assertDatabaseCount('incidencias', 1);
        $ticket = Ticket::where('user_id', $ana->id)->firstOrFail();
        $this->assertSame('Incidencia de conexión', $ticket->asunto);
        $this->assertStringContainsString('Se cayó la red', $ticket->descripcion);
        Mail::assertSent(TicketNuevo::class, fn (TicketNuevo $correo) => $correo->hasTo($tecnico->email));

        $this->actingAs($tecnico)->get(route('tecnico.tickets'))
            ->assertOk()
            ->assertSee('Incidencia de conexión');
        $this->actingAs($tecnico)->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Se cayó la red');
    }

    public function test_una_incidencia_rechazada_no_abre_ticket(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-07 10:45:00', 'Europe/Madrid'));
        User::factory()->tecnico()->create();
        $ana = User::factory()->create();

        $this->actingAs($ana)->post(route('jornada.incidencia'), [
            'motivo' => '',
        ])->assertSessionHasErrors('motivo');

        $this->assertSame(0, Ticket::count());
        $this->assertDatabaseCount('incidencias', 0);
    }

    public function test_si_el_correo_falla_la_incidencia_queda_en_el_registro_y_en_el_ticket(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-07 10:45:00', 'Europe/Madrid'));
        User::factory()->tecnico()->create();
        $ana = User::factory()->create();
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('sin conexión'));

        $this->actingAs($ana)->post(route('jornada.incidencia'), [
            'motivo' => 'Se cayó la red',
        ])->assertSessionHas('ok');

        $this->assertDatabaseCount('tramos', 0);
        $this->assertDatabaseCount('incidencias', 1);
        $this->assertSame(1, Ticket::where('user_id', $ana->id)->count());
    }

    public function test_un_estado_inexistente_se_rechaza(): void
    {
        $ticket = Ticket::factory()->create();

        $this->actingAs(User::factory()->tecnico()->create())
            ->post(route('tecnico.tickets.estado', $ticket), ['estado' => 'inventado'])
            ->assertSessionHasErrors('estado');
    }
}
