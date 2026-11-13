<?php

namespace App\Console\Commands;

use App\Services\CatalogoMunicipios;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('catalogo:actualizar {--snapshot : Instala la copia incluida si todavía no hay catálogo local}')]
#[Description('Actualiza el catálogo nacional de municipios de forma atómica y conserva la versión vigente ante fallos')]
class ActualizarMunicipios extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CatalogoMunicipios $catalogo): int
    {
        try {
            $estado = $this->option('snapshot') ? $catalogo->instalarSnapshot() : $catalogo->actualizar();
        } catch (RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        $this->info('Catálogo local disponible: '.$estado['total'].' municipios. Actualizado: '.$estado['actualizado_at'].' UTC.');
        $this->line('Procedencia: '.$estado['fuente']);
        $this->line('Referencia INE: '.$estado['referencia']);
        $this->line('Descarga: '.$estado['origen']);
        $this->line('SHA-256: '.$estado['sha256']);

        return self::SUCCESS;
    }
}
