<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('centros', function (Blueprint $table) {
            $table->string('municipio_ine', 5)->nullable();
            $table->string('province', 120)->nullable();
            $table->string('province_code', 2)->nullable();
            $table->string('comunidad_codigo', 12)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('centros', function (Blueprint $table) {
            $table->dropColumn(['municipio_ine', 'province', 'province_code', 'comunidad_codigo']);
        });
    }
};
