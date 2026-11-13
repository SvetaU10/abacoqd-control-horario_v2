<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ajustes_saldo_vacaciones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('anio');
            $table->unsignedSmallInteger('saldo_deseado');
            $table->unsignedSmallInteger('aprobados_al_ajuste');
            $table->string('motivo', 500);
            $table->uuid('operacion')->unique();
            $table->timestamp('created_at');
            $table->index(['user_id', 'anio', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ajustes_saldo_vacaciones');
    }
};
