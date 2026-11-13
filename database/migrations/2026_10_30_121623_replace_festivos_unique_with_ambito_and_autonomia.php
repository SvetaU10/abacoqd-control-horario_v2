<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('festivos', function (Blueprint $table) {
            $table->dropUnique(['holiday_date', 'municipality']);
            $table->unique(['holiday_date', 'ambito', 'municipality', 'community']);
        });
    }

    public function down(): void
    {
        Schema::table('festivos', function (Blueprint $table) {
            $table->dropUnique(['holiday_date', 'ambito', 'municipality', 'community']);
            $table->unique(['holiday_date', 'municipality']);
        });
    }
};
