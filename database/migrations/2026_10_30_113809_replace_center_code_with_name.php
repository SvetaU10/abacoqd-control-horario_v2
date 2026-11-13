<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('centros', 'name')) {
            Schema::table('centros', function (Blueprint $table) {
                $table->string('name', 120)->nullable()->unique()->after('id');
            });
        }

        $nombresUsados = DB::table('centros')->whereNotNull('name')->pluck('name')->all();
        foreach (DB::table('centros')->whereNull('name')->orderBy('id')->get(['id', 'municipality']) as $centro) {
            $base = trim((string) preg_replace('/\s+/', ' ', Str::ascii($centro->municipality)));
            $nombre = $base !== '' ? $base : 'Sede';
            $candidato = $nombre;
            $contador = 2;

            while (in_array($candidato, $nombresUsados, true)) {
                $candidato = $nombre.'-'.$contador;
                $contador++;
            }

            $nombresUsados[] = $candidato;
            DB::table('centros')->where('id', $centro->id)->update(['name' => $candidato]);
        }

        if (Schema::hasColumn('users', 'centro')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('centro');
            });
        }

        if (Schema::hasColumn('centros', 'code')) {
            Schema::table('centros', function (Blueprint $table) {
                $table->dropUnique('centros_code_unique');
                $table->dropColumn(['code', 'province']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('centros', function (Blueprint $table) {
            $table->string('code', 30)->nullable()->unique()->after('id');
            $table->string('province')->nullable()->after('municipality');
        });

        foreach (DB::table('centros')->orderBy('id')->get(['id', 'name', 'municipality']) as $centro) {
            DB::table('centros')->where('id', $centro->id)->update([
                'code' => 'ES-'.Str::upper((string) preg_replace('/[^A-Za-z0-9]+/', '-', Str::ascii($centro->name))),
                'province' => '',
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('centro')->nullable()->after('municipality');
        });

        Schema::table('centros', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->dropColumn('name');
        });
    }
};
