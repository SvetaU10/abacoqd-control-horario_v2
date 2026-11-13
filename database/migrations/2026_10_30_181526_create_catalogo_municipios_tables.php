<?php

use App\Services\CatalogoMunicipios;
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
        Schema::create('municipios', function (Blueprint $table) {
            $table->string('ine', 5)->primary();
            $table->string('nombre', 160);
            $table->string('provincia', 120);
            $table->string('provincia_codigo', 2);
            $table->string('comunidad', 120);
            $table->string('comunidad_codigo', 12);
            $table->string('nombre_normalizado', 160)->index();
            $table->string('busqueda', 500);
        });
        Schema::create('catalogo_municipios', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedInteger('total');
            $table->string('fuente', 500);
            $table->string('origen', 500);
            $table->string('referencia', 500);
            $table->timestamp('actualizado_at');
            $table->string('sha256', 64);
        });

        app(CatalogoMunicipios::class)->instalarSnapshot();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalogo_municipios');
        Schema::dropIfExists('municipios');
    }
};
