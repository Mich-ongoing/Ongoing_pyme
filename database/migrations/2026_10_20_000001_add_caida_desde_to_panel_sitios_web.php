<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Vigilancia de caídas: desde cuándo la web no responde (null = en línea)
        Schema::table('panel_sitios_web', function (Blueprint $table) {
            $table->timestamp('caida_desde')->nullable()->after('proxima_verificacion');
        });
    }

    public function down(): void
    {
        Schema::table('panel_sitios_web', function (Blueprint $table) {
            $table->dropColumn('caida_desde');
        });
    }
};
