<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Baja con fecha, reincorporación y traza de cambios en la cuenta.
 *
 * No mueve datos existentes: las cuentas cerradas antes de esta migración
 * conservan su fin de contrato y quedan con la baja sin fecha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->date('baja_on')->nullable()->after('ends_on');
        });

        Schema::create('eventos_cuenta', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('tipo', 32);
            $table->json('detalles');
            $table->string('motivo', 300);
            $table->timestamp('created_at');
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('interrupciones_plantilla', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->date('desde');
            $table->date('hasta');
            $table->foreignId('evento_cuenta_id')->nullable()->constrained('eventos_cuenta')->restrictOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'desde']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interrupciones_plantilla');
        Schema::dropIfExists('eventos_cuenta');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('baja_on');
        });
    }
};
