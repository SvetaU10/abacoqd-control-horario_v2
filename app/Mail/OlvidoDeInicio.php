<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OlvidoDeInicio extends Mailable
{
    use SerializesModels;

    public function __construct(
        public User $persona,
        public string $horaPrevista,
        public bool $conCopia = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tu jornada empezaba a las '.$this->horaPrevista,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'correos.olvido-inicio',
        );
    }
}
