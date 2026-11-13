<?php

namespace Database\Factories;

use App\Models\AccionTecnica;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccionTecnica>
 */
class AccionTecnicaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->tecnico(),
            'accion' => AccionTecnica::COPIA,
            'detalle' => null,
        ];
    }
}
