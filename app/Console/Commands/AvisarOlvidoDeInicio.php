<?php

namespace App\Console\Commands;

use App\Mail\OlvidoDeInicio;
use App\Models\User;
use App\Services\JornadaService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

#[Signature('jornada:avisar-inicio')]
#[Description('Manda un correo a quien no ha empezado la jornada poco después de su hora de inicio')]
class AvisarOlvidoDeInicio extends Command
{
    public function handle(JornadaService $jornada): int
    {
        $ahora = now();
        $enviados = 0;
        $jefas = User::query()
            ->where('active', true)
            ->where('correo_habilitado', true)
            ->jefes()
            ->pluck('email', 'id');

        User::query()->conJornada()
            ->where('active', true)
            ->where('correo_habilitado', true)
            ->orderBy('id')
            ->each(function (User $persona) use ($jornada, $ahora, $jefas, &$enviados): void {
                $horaPrevista = $jornada->avisarDeInicio($persona, $ahora);

                if ($horaPrevista === null) {
                    return;
                }

                $copia = $jefas->except($persona->id)->values()->all();

                try {
                    $this->enviar($persona, $horaPrevista, $copia);
                    $enviados++;
                } catch (Throwable $error) {
                    $jornada->liberarAvisoDeInicio($persona, $ahora);
                    $this->error('No se pudo avisar a '.$persona->email.': '.$error->getMessage());
                    Log::error('Correo de olvido de fichar sin enviar a '.$persona->email.': '.$error->getMessage());
                }
            });

        $this->info('Correos enviados: '.$enviados);

        return self::SUCCESS;
    }

    /**
     * Manda el correo a la persona con copia a los responsables. Si el
     * servidor rechaza la copia (una dirección que no existe, por ejemplo),
     * el aviso a la persona no se pierde: se reintenta sin copia.
     *
     * @param  list<string>  $copia
     */
    private function enviar(User $persona, string $horaPrevista, array $copia): void
    {
        try {
            Mail::to($persona->email)
                ->cc($copia)
                ->send(new OlvidoDeInicio($persona, $horaPrevista, $copia !== []));
        } catch (Throwable $error) {
            if ($copia === []) {
                throw $error;
            }

            Log::warning('No se pudo copiar a el responsable ('.implode(', ', $copia).'): '.$error->getMessage());
            Mail::to($persona->email)->send(new OlvidoDeInicio($persona, $horaPrevista));
        }
    }
}
