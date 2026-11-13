<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ConfirmarCambioCorreo extends Mailable
{
    public function __construct(public string $enlace) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirma tu nuevo correo de acceso');
    }

    public function content(): Content
    {
        return new Content(view: 'correos.confirmar-cambio-correo');
    }
}
