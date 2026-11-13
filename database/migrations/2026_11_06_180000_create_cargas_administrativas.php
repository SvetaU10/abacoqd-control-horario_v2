<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carga_administrativa_lotes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('autor_id')->constrained('users')->restrictOnDelete();
            $table->date('desde');
            $table->date('hasta');
            $table->date('fecha_corte');
            $table->json('participantes');
            $table->json('horario');
            $table->json('referencias');
            $table->json('exclusiones');
            $table->unsignedInteger('vacaciones_pendientes_incluidas');
            $table->string('plan_hash', 64)->unique();
            $table->string('motivo', 500);
            $table->timestamps();
        });

        Schema::create('carga_administrativa_dias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lote_id')->constrained('carga_administrativa_lotes')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->date('fecha');
            $table->string('clase', 32);
            $table->string('estado', 32)->default('vigente');
            $table->string('clave_activa', 64)->nullable()->unique();
            $table->time('morning_start');
            $table->time('morning_end');
            $table->time('afternoon_start');
            $table->time('afternoon_end');
            $table->unsignedSmallInteger('minutos');
            $table->foreignId('jornada_sustituta_id')->nullable()->constrained('jornadas')->restrictOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'fecha', 'estado']);
        });

        Schema::create('carga_administrativa_eventos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dia_id')->constrained('carga_administrativa_dias')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('tipo', 32);
            $table->string('motivo', 500);
            $table->json('detalles');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carga_administrativa_eventos');
        Schema::dropIfExists('carga_administrativa_dias');
        Schema::dropIfExists('carga_administrativa_lotes');
    }
};
