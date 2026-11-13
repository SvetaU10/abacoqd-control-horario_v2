<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El papel `responsable` pasa a llamarse `jefa`. Solo cambia el nombre: hace lo mismo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('role', 'responsable')->update(['role' => 'jefa']);
    }

    public function down(): void
    {
        DB::table('users')->where('role', 'jefa')->update(['role' => 'responsable']);
    }
};
