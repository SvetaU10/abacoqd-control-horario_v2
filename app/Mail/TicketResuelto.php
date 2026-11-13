<?php

namespace App\Mail;

use App\Models\Ticket;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TicketResuelto extends Mailable
{
    use SerializesModels;

    public function __construct(public Ticket $ticket) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tu ticket está resuelto: '.$this->ticket->asunto,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'correos.ticket-resuelto',
        );
    }
}
