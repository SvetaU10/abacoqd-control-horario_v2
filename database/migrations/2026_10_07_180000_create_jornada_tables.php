<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('trabajadora');
            $table->boolean('active')->default(true);
            $table->date('starts_on')->nullable();
            $table->string('municipality')->nullable();
        });

        Schema::create('horarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->time('morning_start');
            $table->time('morning_end');
            $table->time('afternoon_start');
            $table->time('afternoon_end');
            $table->date('effective_from');
            $table->timestamps();
            $table->index(['user_id', 'effective_from']);
        });

        Schema::create('jornadas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('work_date');
            $table->boolean('closed_late')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'work_date']);
        });

        Schema::create('tramos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jornada_id')->constrained()->restrictOnDelete();
            $table->string('tipo');
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->timestamps();
            $table->index('jornada_id');
        });

        Schema::create('correcciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tramo_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('field');
            $table->dateTime('previous_value');
            $table->dateTime('new_value');
            $table->string('reason');
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('festivos', function (Blueprint $table) {
            $table->id();
            $table->date('holiday_date');
            $table->string('municipality')->default('');
            $table->string('name');
            $table->timestamps();
            $table->unique(['holiday_date', 'municipality']);
        });

        Schema::create('ausencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('absence_date');
            $table->string('tipo');
            $table->timestamps();
            $table->unique(['user_id', 'absence_date']);
        });

        Schema::create('avisos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('tipo');
            $table->date('work_date');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'tipo', 'work_date']);
        });

        Schema::create('consultas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('viewer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('subject_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('explicaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('explicaciones');
        Schema::dropIfExists('consultas');
        Schema::dropIfExists('avisos');
        Schema::dropIfExists('ausencias');
        Schema::dropIfExists('festivos');
        Schema::dropIfExists('correcciones');
        Schema::dropIfExists('tramos');
        Schema::dropIfExists('jornadas');
        Schema::dropIfExists('horarios');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'active', 'starts_on', 'municipality']);
        });
    }
};
