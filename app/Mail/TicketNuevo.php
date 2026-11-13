<?php

namespace App\Mail;

use App\Models\Ticket;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TicketNuevo extends Mailable
{
    use SerializesModels;

    public function __construct(public Ticket $ticket) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Ticket nuevo: '.$this->ticket->asunto,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'correos.ticket-nuevo',
        );
    }
}
