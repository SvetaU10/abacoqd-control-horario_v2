<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('registra_jornada')->default(true);
        });

        DB::table('users')->where('role', 'jefa')->update(['role' => 'jefe']);
        DB::table('users')->where('role', 'trabajadora')->update(['role' => 'trabajador']);
    }

    public function down(): void
    {
        // Una cuenta creada solo para IT nunca se convierte en fichadora al volver atrás.
        DB::table('users')->where('registra_jornada', false)->update(['active' => false]);
        DB::table('users')->where('role', 'jefe')->update(['role' => 'jefa']);
        DB::table('users')->where('role', 'trabajador')->update(['role' => 'trabajadora']);

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('registra_jornada');
        });
    }
};
