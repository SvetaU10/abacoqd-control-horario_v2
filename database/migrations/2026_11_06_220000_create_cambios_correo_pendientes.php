<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite normally compares text case-sensitively; enforce the same rule
        // as the application even when two requests race.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('CREATE UNIQUE INDEX users_email_normalizado_unico ON users (LOWER(email))');
        }
        Schema::create('cambios_correo_pendientes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('correo_nuevo', 190);
            $table->string('token_hash', 64);
            $table->string('password_version_hash', 64);
            $table->string('correo_original_hash', 64);
            $table->timestamp('vence_at');
            $table->timestamps();
        });
        Schema::create('eventos_cambio_correo', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('correo_anterior', 190);
            $table->string('correo_nuevo', 190);
            $table->timestamp('created_at');
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos_cambio_correo');
        Schema::dropIfExists('cambios_correo_pendientes');
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS users_email_normalizado_unico');
        }
    }
};
