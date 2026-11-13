<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jornadas', function (Blueprint $table) {
            $table->boolean('sigue')->default(false);
        });

        Schema::table('tramos', function (Blueprint $table) {
            $table->dateTime('anotado_at')->nullable();
            $table->boolean('fuera_del_equipo')->default(false);
            $table->string('situacion')->nullable();
        });

        Schema::table('avisos', function (Blueprint $table) {
            $table->boolean('cuenta')->default(true);
            $table->string('exclusion')->nullable();
        });

        Schema::table('consultas', function (Blueprint $table) {
            $table->string('reason')->nullable();
        });

        Schema::table('explicaciones', function (Blueprint $table) {
            $table->date('work_date')->nullable();
        });

        Schema::create('fallos_comunes', function (Blueprint $table) {
            $table->id();
            $table->date('falla_date')->unique();
            $table->string('note');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fallos_comunes');

        Schema::table('explicaciones', function (Blueprint $table) {
            $table->dropColumn('work_date');
        });
        Schema::table('consultas', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
        Schema::table('avisos', function (Blueprint $table) {
            $table->dropColumn(['cuenta', 'exclusion']);
        });
        Schema::table('tramos', function (Blueprint $table) {
            $table->dropColumn(['anotado_at', 'fuera_del_equipo', 'situacion']);
        });
        Schema::table('jornadas', function (Blueprint $table) {
            $table->dropColumn('sigue');
        });
    }
};
