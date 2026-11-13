<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('festivos', function (Blueprint $table) {
            $table->string('ambito')->default('pueblo')->after('name');
        });

        $estado = [
            '2026-01-01',
            '2026-01-06',
            '2026-04-03',
            '2026-05-01',
            '2026-08-15',
            '2026-10-12',
            '2026-11-02',
            '2026-12-07',
            '2026-12-08',
            '2026-12-25',
        ];
        $comunidad = [
            '2026-04-02',
            '2026-05-02',
        ];

        foreach ($estado as $fecha) {
            DB::table('festivos')->whereDate('holiday_date', $fecha)->update(['ambito' => 'estado']);
        }
        foreach ($comunidad as $fecha) {
            DB::table('festivos')->whereDate('holiday_date', $fecha)->update(['ambito' => 'comunidad']);
        }
    }

    public function down(): void
    {
        Schema::table('festivos', function (Blueprint $table) {
            $table->dropColumn('ambito');
        });
    }
};
