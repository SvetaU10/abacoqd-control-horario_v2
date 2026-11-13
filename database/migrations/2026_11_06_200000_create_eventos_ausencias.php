<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eventos_ausencias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('ausencia_original_id');
            $table->date('absence_date');
            $table->string('tipo', 32);
            $table->timestamp('ausencia_creada_at')->nullable();
            $table->string('motivo', 500);
            $table->foreignId('carga_dia_id')->nullable()->constrained('carga_administrativa_dias')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->index(['user_id', 'absence_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos_ausencias');
    }
};
