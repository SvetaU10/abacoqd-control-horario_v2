<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tramos', function (Blueprint $table) {
            $table->text('nota')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tramos', function (Blueprint $table) {
            $table->dropColumn('nota');
        });
    }
};
