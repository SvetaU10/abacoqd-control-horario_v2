<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asignaciones_centro', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('centro_id')->constrained('centros')->restrictOnDelete();
            $table->date('desde')->nullable();
            $table->date('hasta')->nullable();
            $table->timestamp('cancelado_at')->nullable();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'cancelado_at', 'desde'], 'asignaciones_centro_consulta');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asignaciones_centro');
    }
};
