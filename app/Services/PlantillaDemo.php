<?php

namespace App\Services;

use App\Models\Ausencia;
use App\Models\Centro;
use App\Models\Horario;
use App\Models\User;
use App\TipoJornada;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

class PlantillaDemo
{
    public const CLAVE = 'Jornada2026';

    public function __construct(private ?string $archivo = null)
    {
        $this->archivo ??= database_path('seeders/data/personas.json');
    }

    /**
     * Importa únicamente el fixture sintético, sin escribir en el repositorio.
     */
    public function aplicar(): void
    {
        if (! app()->environment('local', 'testing') || (! app()->runningUnitTests() && ! config('app.demo'))) {
            throw new RuntimeException('Las cuentas ficticias sólo se pueden cargar en una demo local explícita.');
        }
        DB::transaction(function (): void {
            foreach ($this->leer() as $fila) {
                if (! is_array($fila) || ! str_ends_with((string) ($fila['email'] ?? ''), '@abaco.test')) {
                    throw new RuntimeException('El fixture demo sólo admite direcciones sintéticas @abaco.test.');
                }
                $this->aplicarPersona($fila);
            }
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function leer(): array
    {
        if (! is_file($this->archivo)) {
            throw new RuntimeException('No está disponible el fixture de demostración.');
        }

        $datos = json_decode((string) File::get($this->archivo), true);
        if (! is_array($datos) || ! array_is_list($datos)) {
            throw new RuntimeException('El fixture de demostración no contiene una lista válida.');
        }

        return $datos;
    }

    /**
     * @param  array<string, mixed>  $fila
     */
    private function aplicarPersona(array $fila): void
    {
        $correo = (string) ($fila['email'] ?? '');
        if ($correo === '') {
            return;
        }

        $sede = Centro::query()->where('name', $fila['centro'] ?? '')->first()
            ?? Centro::query()->orderBy('name')->firstOrFail();
        $persona = User::query()->firstOrCreate(
            ['email' => $correo],
            [
                'name' => (string) ($fila['name'] ?? $correo),
                'password' => self::CLAVE,
                'role' => (string) ($fila['role'] ?? 'trabajador'),
                'active' => true,
                'starts_on' => $fila['starts_on'] ?? null,
                'ends_on' => $fila['ends_on'] ?? null,
                'municipality' => (string) ($fila['municipality'] ?? $sede->municipio()),
                'centro_id' => $sede->id,
            ],
        );

        if (! $persona->wasRecentlyCreated) {
            return;
        }

        $desde = Carbon::parse((string) ($fila['effective_from'] ?? $fila['starts_on'] ?? now()->toDateString()))->toDateString();
        if (! Horario::query()->where('user_id', $persona->id)->whereDate('effective_from', $desde)->exists()) {
            Horario::create([
                'user_id' => $persona->id,
                'tipo' => $fila['tipo'] ?? TipoJornada::Partida->value,
                'morning_start' => $fila['morning_start'] ?? '09:00',
                'morning_end' => $fila['morning_end'] ?? '14:00',
                'afternoon_start' => $fila['afternoon_start'] ?? '15:00',
                'afternoon_end' => $fila['afternoon_end'] ?? '18:00',
                'effective_from' => $desde,
            ]);
        }

        foreach ($fila['ausencias'] ?? [] as $ausencia) {
            if (! is_array($ausencia) || ! isset($ausencia['absence_date'])) {
                continue;
            }

            $fecha = Carbon::parse((string) $ausencia['absence_date'])->toDateString();
            if (Ausencia::query()->where('user_id', $persona->id)->whereDate('absence_date', $fecha)->exists()) {
                continue;
            }

            Ausencia::create([
                'user_id' => $persona->id,
                'absence_date' => $fecha,
                'tipo' => $ausencia['tipo'] ?? 'vacaciones',
            ]);
        }
    }
}
