<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidencias', function (Blueprint $table) {
            $table->timestamp('vista_at')->nullable()->after('cuerpo');
        });

        DB::table('incidencias')->whereNull('vista_at')->update([
            'vista_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('incidencias', function (Blueprint $table) {
            $table->dropColumn('vista_at');
        });
    }
};
