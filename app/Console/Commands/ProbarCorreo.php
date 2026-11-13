<?php

namespace App\Console\Commands;

use App\Mail\OlvidoDeInicio;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

#[Signature('jornada:probar-correo {destino : Dirección que recibirá el correo de prueba}')]
#[Description('Manda ahora el correo de olvido de fichar a una dirección, para comprobar que el envío funciona')]
class ProbarCorreo extends Command
{
    public function handle(): int
    {
        $destino = (string) $this->argument('destino');

        if (! filter_var($destino, FILTER_VALIDATE_EMAIL)) {
            $this->error('"'.$destino.'" no parece una dirección de correo.');

            return self::FAILURE;
        }

        if (User::query()->where('email', $destino)->where('correo_habilitado', false)->exists()) {
            $this->error('El correo de esta cuenta está deshabilitado para envíos.');

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');
        $this->line('Servicio: '.$mailer.' · Remitente: '.config('mail.from.address'));

        if (in_array($mailer, ['log', 'array'], true)) {
            $this->warn('Ahora mismo el correo NO sale a ninguna bandeja: MAIL_MAILER es "'.$mailer.'". Cambia el .env para enviarlo de verdad.');
        } else {
            $this->line('Servidor: '.config('mail.mailers.'.$mailer.'.host').':'.config('mail.mailers.'.$mailer.'.port'));
        }

        $persona = new User(['name' => 'persona de prueba', 'email' => $destino]);

        try {
            Mail::to($destino)->send(new OlvidoDeInicio($persona, '09:00'));
        } catch (Throwable $error) {
            $this->error('No se pudo enviar: '.$error->getMessage());

            return self::FAILURE;
        }

        $this->info('Enviado a '.$destino.'. Mira la bandeja de entrada (y la de spam).');

        return self::SUCCESS;
    }
}
