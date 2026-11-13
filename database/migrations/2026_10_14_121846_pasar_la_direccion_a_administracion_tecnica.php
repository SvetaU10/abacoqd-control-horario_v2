<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El papel de dirección desaparece: sus funciones las hace la jefa y las
 * cuentas que lo tenían pasan a ser de administración técnica.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('role', 'jefe')->update(['role' => 'tecnico']);
    }

    public function down(): void
    {
        DB::table('users')->where('role', 'tecnico')->update(['role' => 'jefe']);
    }
};
