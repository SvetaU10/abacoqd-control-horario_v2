<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('importaciones_calendario', function (Blueprint $table): void {
            $table->date('vigente_desde')->nullable();
        });
        Schema::create('vigencias_calendario', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('centro_id')->constrained('centros')->cascadeOnDelete();
            $table->unsignedSmallInteger('anio');
            $table->foreignId('importacion_id')->constrained('importaciones_calendario')->restrictOnDelete();
            $table->date('desde');
            $table->date('hasta');
            $table->timestamp('cancelado_at')->nullable();
            $table->timestamps();
            $table->index(['centro_id', 'anio', 'cancelado_at', 'desde'], 'vigencias_calendario_consulta');
        });

        DB::table('importaciones_calendario')->orderBy('id')->chunkById(200, function ($importaciones): void {
            foreach ($importaciones as $importacion) {
                $desde = sprintf('%04d-01-01', $importacion->anio);
                DB::table('importaciones_calendario')->where('id', $importacion->id)->update(['vigente_desde' => $desde]);
                if ($importacion->clave_activa !== null) {
                    DB::table('vigencias_calendario')->insert([
                        'centro_id' => $importacion->centro_id, 'anio' => $importacion->anio,
                        'importacion_id' => $importacion->id, 'desde' => $desde,
                        'hasta' => sprintf('%04d-12-31', $importacion->anio), 'cancelado_at' => null,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vigencias_calendario');
        Schema::table('importaciones_calendario', function (Blueprint $table): void {
            $table->dropColumn('vigente_desde');
        });
    }
};
