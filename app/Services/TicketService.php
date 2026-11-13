<?php

namespace App\Services;

use App\Mail\TicketNuevo;
use App\Mail\TicketResuelto;
use App\Models\Ticket;
use App\Models\TicketMensaje;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tickets: una persona cuenta un problema y el administrador técnico lo
 * resuelve. Un correo que falle nunca impide guardar el ticket.
 */
class TicketService
{
    public function abrir(User $autor, string $asunto, string $descripcion, ?string $pagina): Ticket
    {
        $ticket = Ticket::create([
            'user_id' => $autor->id,
            'asunto' => $asunto,
            'descripcion' => $descripcion,
            'pagina' => $pagina,
            'estado' => Ticket::ABIERTO,
        ]);

        $tecnicos = User::query()
            ->where('role', 'tecnico')
            ->where('active', true)
            ->where('correo_habilitado', true)
            ->whereKeyNot($autor->id)
            ->pluck('email')
            ->all();

        if ($tecnicos !== []) {
            $this->enviar($ticket, fn () => Mail::to($tecnicos)->send(new TicketNuevo($ticket)));
        }

        return $ticket;
    }

    /**
     * Quien lo abrió puede reabrir un ticket resuelto escribiendo de nuevo.
     * Cuando el técnico contesta a uno abierto, pasa a en curso.
     */
    public function responder(Ticket $ticket, User $autor, string $cuerpo): TicketMensaje
    {
        return DB::transaction(function () use ($ticket, $autor, $cuerpo): TicketMensaje {
            $ticket = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $mensaje = $ticket->mensajes()->create([
                'user_id' => $autor->id,
                'cuerpo' => $cuerpo,
            ]);

            if ($ticket->estaResuelto() && $ticket->user_id === $autor->id) {
                $ticket->update(['estado' => Ticket::ABIERTO, 'resuelto_at' => null]);
            } elseif ($autor->esTecnico() && $ticket->user_id !== $autor->id) {
                $datos = ['respuesta_vista_at' => null];
                if ($ticket->estado === Ticket::ABIERTO) {
                    $datos['estado'] = Ticket::EN_CURSO;
                }
                $ticket->update($datos);
            }

            return $mensaje;
        });
    }

    /**
     * @param  string  $estado  Uno de los valores de Ticket::ESTADOS
     */
    public function cambiarEstado(Ticket $ticket, string $estado): void
    {
        $eraResuelto = $ticket->estaResuelto();

        $ticket->update([
            'estado' => $estado,
            'resuelto_at' => $estado === Ticket::RESUELTO ? ($ticket->resuelto_at ?? now()) : null,
        ]);

        if ($estado === Ticket::RESUELTO && ! $eraResuelto && $ticket->autor->correo_habilitado) {
            $this->enviar($ticket, fn () => Mail::to($ticket->autor->email)->send(new TicketResuelto($ticket)));
        }
    }

    private function enviar(Ticket $ticket, callable $envio): void
    {
        try {
            $envio();
        } catch (Throwable $error) {
            Log::warning('No se pudo avisar por correo del ticket '.$ticket->id.': '.$error->getMessage());
        }
    }
}
