<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('importaciones_calendario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('centro_id')->constrained('centros')->cascadeOnDelete();
            $table->unsignedSmallInteger('anio');
            $table->string('municipio_ine', 5);
            $table->string('municipio', 160)->default('');
            $table->string('provincia', 120)->default('');
            $table->string('comunidad', 120)->default('');
            $table->string('comunidad_codigo', 12)->default('');
            $table->string('estado', 32);
            $table->text('mensaje');
            $table->json('festivos')->nullable();
            $table->json('fuentes')->nullable();
            $table->string('hash', 64)->nullable();
            $table->string('clave_activa', 40)->nullable()->unique();
            $table->timestamp('consultado_at');
            $table->timestamp('activado_at')->nullable();
            $table->foreignId('consultado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('activado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['centro_id', 'anio', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('importaciones_calendario');
    }
};
