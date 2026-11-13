<?php

namespace Database\Factories;

use App\Models\Centro;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => 'trabajador',
            'registra_jornada' => true,
            'active' => true,
            'starts_on' => '2026-01-07',
            'municipality' => 'Madrid',
            'centro_id' => Centro::query()->where('name', 'Madrid')->value('id'),
        ];
    }

    /**
     * Jefa: gestiona el equipo y hace las funciones de dirección.
     */
    public function jefa(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'jefe',
        ]);
    }

    /**
     * Administrador técnico: ficha y gestiona actualizaciones y base de datos.
     */
    public function tecnico(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'tecnico',
        ]);
    }

    public function soloTecnico(): static
    {
        return $this->tecnico()->state(fn (array $attributes) => [
            'registra_jornada' => false,
            'starts_on' => null,
            'ends_on' => null,
            'municipality' => null,
            'centro_id' => null,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
