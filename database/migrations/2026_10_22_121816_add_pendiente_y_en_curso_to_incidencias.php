<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('incidencias')->where('estado', 'abierta')->update(['estado' => 'pendiente']);
    }

    public function down(): void
    {
        DB::table('incidencias')->where('estado', 'pendiente')->update(['estado' => 'abierta']);
    }
};
