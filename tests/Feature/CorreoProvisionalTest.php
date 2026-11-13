<?php

namespace Tests\Feature;

use App\Mail\OlvidoDeInicio;
use App\Mail\RecuperarClave;
use App\Mail\TicketNuevo;
use App\Mail\TicketResuelto;
use App\Models\Horario;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class CorreoProvisionalTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_bloqueo_no_impide_entrar_y_solo_suprime_recuperacion_de_esa_cuenta(): void
    {
        Mail::fake();
        $provisional = User::factory()->create(['correo_habilitado' => false]);
        $otra = User::factory()->create();
        $tokenAnterior = Password::broker()->createToken($provisional);

        $this->post(route('login'), ['email' => $provisional->email, 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticatedAs($provisional);
        $this->post(route('logout'));
        $this->post(route('password.email'), ['email' => $provisional->email])->assertSessionHas('ok');
        $this->post(route('password.update'), ['email' => $provisional->email, 'token' => $tokenAnterior,
            'password' => 'NuevaClave123', 'password_confirmation' => 'NuevaClave123'])->assertSessionHas('aviso');
        Mail::assertNothingSent();
        $this->post(route('password.email'), ['email' => $otra->email])->assertSessionHas('ok');
        Mail::assertSent(RecuperarClave::class, fn (RecuperarClave $mail) => $mail->hasTo($otra->email));
        $this->artisan('jornada:probar-correo', ['destino' => $provisional->email])->assertFailed();
        Mail::assertSentCount(1);
    }

    public function test_los_recordatorios_omiten_destinatario_y_copia_bloqueados_sin_afectar_a_los_demas(): void
    {
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-10-07 09:30:00', 'Europe/Madrid'));
        $normal = User::factory()->create();
        $provisional = User::factory()->create(['correo_habilitado' => false]);
        $jefeBloqueado = User::factory()->jefa()->create(['correo_habilitado' => false]);
        $this->calendarioConfirmado($normal->sede, 2026);
        foreach ([$normal, $provisional, $jefeBloqueado] as $persona) {
            Horario::create(['user_id' => $persona->id, 'morning_start' => '09:00', 'morning_end' => '14:00',
                'afternoon_start' => '15:00', 'afternoon_end' => '18:00', 'effective_from' => '2026-01-07']);
        }
        $this->artisan('jornada:avisar-inicio')->assertSuccessful();
        Mail::assertSent(OlvidoDeInicio::class, 1);
        Mail::assertSent(OlvidoDeInicio::class, fn (OlvidoDeInicio $mail) => $mail->hasTo($normal->email)
            && ! $mail->hasCc($jefeBloqueado->email));
        $this->assertDatabaseMissing('avisos', ['user_id' => $provisional->id, 'tipo' => 'inicio']);
    }

    public function test_los_tickets_omiten_correos_bloqueados_y_reanudan_envios_si_se_habilitan(): void
    {
        Mail::fake();
        $autor = User::factory()->create(['correo_habilitado' => false]);
        $tecnico = User::factory()->soloTecnico()->create();
        $tecnicoBloqueado = User::factory()->soloTecnico()->create(['correo_habilitado' => false]);
        $servicio = app(TicketService::class);
        $ticket = $servicio->abrir($autor, 'Prueba', 'Texto', null);
        Mail::assertSent(TicketNuevo::class, fn (TicketNuevo $mail) => $mail->hasTo($tecnico->email) && ! $mail->hasTo($tecnicoBloqueado->email));
        $servicio->cambiarEstado($ticket, Ticket::RESUELTO);
        Mail::assertNotSent(TicketResuelto::class);

        $autor->forceFill(['correo_habilitado' => true])->save();
        $ticket->unsetRelation('autor');
        $servicio->cambiarEstado($ticket, Ticket::ABIERTO);
        $servicio->cambiarEstado($ticket, Ticket::RESUELTO);
        Mail::assertSent(TicketResuelto::class, fn (TicketResuelto $mail) => $mail->hasTo($autor->email));
    }
}
