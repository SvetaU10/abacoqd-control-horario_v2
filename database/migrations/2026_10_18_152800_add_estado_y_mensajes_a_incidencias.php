<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidencias', function (Blueprint $table) {
            $table->string('estado')->default('abierta')->after('cuerpo');
            $table->timestamp('resuelta_at')->nullable()->after('estado');
        });

        Schema::create('incidencia_mensajes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incidencia_id')->constrained('incidencias')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('cuerpo');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidencia_mensajes');

        Schema::table('incidencias', function (Blueprint $table) {
            $table->dropColumn(['estado', 'resuelta_at']);
        });
    }
};
