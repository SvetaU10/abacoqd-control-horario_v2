<?php

namespace Database\Factories;

use App\Models\Ticket;
use App\Models\TicketMensaje;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketMensaje>
 */
class TicketMensajeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'user_id' => User::factory(),
            'cuerpo' => fake()->sentence(),
        ];
    }
}
