<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RecuperarClave extends Mailable
{
    use SerializesModels;

    public string $enlace;

    public function __construct(public User $persona, string $token)
    {
        $this->enlace = url(route('password.reset', [
            'token' => $token,
            'email' => $persona->getEmailForPasswordReset(),
        ], false));
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Cambia tu contraseña',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'correos.recuperar-clave',
        );
    }
}
