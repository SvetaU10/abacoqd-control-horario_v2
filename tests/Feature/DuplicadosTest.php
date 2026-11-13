<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DuplicadosTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_ausencia_repetida_se_avisa_y_no_se_duplica(): void
    {
        $jefa = User::factory()->create(['role' => 'jefa']);
        $ana = User::factory()->create();
        $datos = ['absence_date' => '2026-10-20', 'tipo' => 'permiso'];

        $this->actingAs($jefa)->post(route('equipo.ausencia', $ana), $datos)->assertSessionHas('ok');
        $this->actingAs($jefa)->post(route('equipo.ausencia', $ana), $datos)
            ->assertSessionHas('aviso', 'Ese día ya tiene una ausencia.');

        $this->assertDatabaseCount('ausencias', 1);
    }
}
