<?php

namespace Database\Factories;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'asunto' => fake()->sentence(4),
            'descripcion' => fake()->paragraph(),
            'pagina' => '/',
            'estado' => Ticket::ABIERTO,
            'resuelto_at' => null,
        ];
    }

    public function enCurso(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => Ticket::EN_CURSO,
        ]);
    }

    public function resuelto(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => Ticket::RESUELTO,
            'resuelto_at' => now(),
        ]);
    }
}
