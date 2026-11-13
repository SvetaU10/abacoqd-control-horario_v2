<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tramos', function (Blueprint $table) {
            $table->foreignId('cerrado_por')->nullable()->after('nota')->constrained('users')->nullOnDelete();
            $table->dateTime('cerrado_at')->nullable()->after('cerrado_por');
        });
    }

    public function down(): void
    {
        Schema::table('tramos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cerrado_por');
            $table->dropColumn('cerrado_at');
        });
    }
};
