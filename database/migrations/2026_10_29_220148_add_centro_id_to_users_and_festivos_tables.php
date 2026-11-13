<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('centro_id')->nullable()->after('centro')->constrained('centros')->nullOnDelete();
        });

        Schema::table('festivos', function (Blueprint $table) {
            $table->foreignId('centro_id')->nullable()->after('municipality')->constrained('centros')->nullOnDelete();
            $table->string('community')->nullable()->after('ambito');
            $table->index(['holiday_date', 'ambito', 'centro_id', 'community']);
        });

        $centros = [
            ['code' => 'ES-MD', 'municipality' => 'Madrid', 'province' => 'Madrid', 'community' => 'Madrid'],
            ['code' => 'ES-CT', 'municipality' => 'Barcelona', 'province' => 'Barcelona', 'community' => 'Cataluña'],
            ['code' => 'ES-AN', 'municipality' => 'Sevilla', 'province' => 'Sevilla', 'community' => 'Andalucía'],
            ['code' => 'ES-VC', 'municipality' => 'Valencia', 'province' => 'Valencia', 'community' => 'Comunitat Valenciana'],
            ['code' => 'ES-PV', 'municipality' => 'Bilbao', 'province' => 'Bizkaia', 'community' => 'País Vasco'],
            ['code' => 'ES-GA', 'municipality' => 'A Coruña', 'province' => 'A Coruña', 'community' => 'Galicia'],
            ['code' => 'ES-AR', 'municipality' => 'Zaragoza', 'province' => 'Zaragoza', 'community' => 'Aragón'],
            ['code' => 'ES-IB', 'municipality' => 'Palma', 'province' => 'Illes Balears', 'community' => 'Islas Baleares'],
            ['code' => 'ES-MC', 'municipality' => 'Murcia', 'province' => 'Murcia', 'community' => 'Región de Murcia'],
            ['code' => 'ES-CL', 'municipality' => 'Valladolid', 'province' => 'Valladolid', 'community' => 'Castilla y León'],
            ['code' => 'ES-CM', 'municipality' => 'Toledo', 'province' => 'Toledo', 'community' => 'Castilla-La Mancha'],
            ['code' => 'ES-EX', 'municipality' => 'Mérida', 'province' => 'Badajoz', 'community' => 'Extremadura'],
            ['code' => 'ES-AS', 'municipality' => 'Oviedo', 'province' => 'Asturias', 'community' => 'Asturias'],
            ['code' => 'ES-CB', 'municipality' => 'Santander', 'province' => 'Cantabria', 'community' => 'Cantabria'],
            ['code' => 'ES-RI', 'municipality' => 'Logroño', 'province' => 'La Rioja', 'community' => 'La Rioja'],
            ['code' => 'ES-NC', 'municipality' => 'Pamplona', 'province' => 'Navarra', 'community' => 'Navarra'],
            ['code' => 'ES-CN', 'municipality' => 'Santa Cruz de Tenerife', 'province' => 'Santa Cruz de Tenerife', 'community' => 'Canarias'],
            ['code' => 'ES-CE', 'municipality' => 'Ceuta', 'province' => 'Ceuta', 'community' => 'Ceuta'],
            ['code' => 'ES-ML', 'municipality' => 'Melilla', 'province' => 'Melilla', 'community' => 'Melilla'],
        ];

        $codigosEnUso = DB::table('users')->whereNotNull('centro')->distinct()->pluck('centro')->all();
        $municipiosEnUso = DB::table('festivos')->where('ambito', '!=', 'estado')->distinct()->pluck('municipality')->all();
        foreach ($centros as $centro) {
            if (! in_array($centro['code'], $codigosEnUso, true) && ! in_array($centro['municipality'], $municipiosEnUso, true)) {
                continue;
            }
            DB::table('centros')->insertOrIgnore($centro + [
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (DB::table('users')->whereNotNull('centro')->get(['id', 'centro']) as $user) {
            $centroId = DB::table('centros')->where('code', $user->centro)->value('id');
            if ($centroId !== null) {
                DB::table('users')->where('id', $user->id)->update(['centro_id' => $centroId]);
            }
        }

        foreach (DB::table('festivos')->whereNotNull('municipality')->get(['id', 'municipality', 'ambito']) as $festivo) {
            if ($festivo->municipality === '' || $festivo->ambito === 'estado') {
                continue;
            }

            $centroId = DB::table('centros')->where('municipality', $festivo->municipality)->value('id');
            if ($centroId !== null) {
                DB::table('festivos')->where('id', $festivo->id)->update(['centro_id' => $centroId]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('festivos', function (Blueprint $table) {
            $table->dropIndex(['holiday_date', 'ambito', 'centro_id', 'community']);
            $table->dropForeign(['centro_id']);
            $table->dropColumn(['centro_id', 'community']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['centro_id']);
            $table->dropColumn('centro_id');
        });
    }
};
