<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('panel_ejecuciones', function (Blueprint $table) {
            $table->string('progreso')->nullable()->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('panel_ejecuciones', function (Blueprint $table) {
            $table->dropColumn('progreso');
        });
    }
};
