<?php

namespace App\Console\Commands;

use App\Models\Centro;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

#[Signature('abaco:crear-administrador {email : Correo de acceso} {nombre : Nombre visible} {--rol=jefe : jefe, trabajador o tecnico} {--solo-tecnico : Cuenta de soporte sin registro laboral; requiere --rol=tecnico} {--centro= : Id de la sede; obligatorio si hay varias para una cuenta laboral} {--desde= : Fecha inicial del registro, YYYY-MM-DD; sin valor queda pendiente} {--hasta= : Fecha final del registro, YYYY-MM-DD; requiere --desde}')]
#[Description('Crea una cuenta con contraseña oculta y sin horario ni actividad; no modifica cuentas existentes')]
class CrearAdministrador extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $soloTecnico = (bool) $this->option('solo-tecnico');
        $datos = [
            'email' => strtolower(trim((string) $this->argument('email'))),
            'name' => trim((string) $this->argument('nombre')),
            'role' => $this->option('rol'),
            'starts_on' => $this->option('desde'),
            'ends_on' => $this->option('hasta'),
        ];
        $validacion = Validator::make($datos, [
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', 'in:jefe,trabajador,tecnico'],
            'starts_on' => ['nullable', 'required_with:ends_on', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ]);
        if ($validacion->fails()) {
            $this->error($validacion->errors()->first());

            return self::FAILURE;
        }
        if ($soloTecnico && ($datos['role'] !== 'tecnico' || $datos['starts_on'] !== null || $datos['ends_on'] !== null || $this->option('centro') !== null)) {
            $this->error('--solo-tecnico requiere --rol=tecnico y no admite fechas laborales ni sede.');

            return self::FAILURE;
        }
        if (User::query()->where('email', $datos['email'])->exists()) {
            $this->error('Ese correo ya tiene una cuenta. No se ha cambiado su contraseña ni su papel.');

            return self::FAILURE;
        }
        $centro = null;
        if (! $soloTecnico) {
            $centros = Centro::query()->when($this->option('centro'), fn ($consulta) => $consulta->whereKey($this->option('centro')))->get();
            if ($centros->count() !== 1) {
                $this->error('Debe existir una única sede o indicar su id mediante --centro. Ejecuta db:seed para el arranque de AbacoQD.');

                return self::FAILURE;
            }
            $centro = $centros->sole();
        }
        if (! $this->input->isInteractive()) {
            $this->error('La contraseña debe introducirse de forma interactiva y oculta. No se admiten claves en argumentos.');

            return self::FAILURE;
        }
        $clave = $this->secret('Contraseña (mínimo 12 caracteres)');
        $repetida = $this->secret('Repite la contraseña');
        if (! is_string($clave) || mb_strlen($clave) < 12 || $clave !== $repetida) {
            $this->error('La contraseña necesita al menos 12 caracteres y las dos entradas deben coincidir.');

            return self::FAILURE;
        }
        DB::transaction(fn () => User::create($datos + [
            'password' => $clave, 'active' => true, 'centro_id' => $centro?->id,
            'municipality' => $centro?->municipality, 'registra_jornada' => ! $soloTecnico,
        ]));
        $this->info('Cuenta creada. No se han añadido horarios ni fichajes.');

        return self::SUCCESS;
    }
}
