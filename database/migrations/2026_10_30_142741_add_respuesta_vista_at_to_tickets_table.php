<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('respuesta_vista_at')->nullable()->after('resuelto_at');
            $table->index(['user_id', 'respuesta_vista_at']);
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'respuesta_vista_at']);
            $table->dropColumn('respuesta_vista_at');
        });
    }
};
