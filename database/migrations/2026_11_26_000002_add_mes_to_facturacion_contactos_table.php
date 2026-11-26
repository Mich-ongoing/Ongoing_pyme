<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // mes null = para siempre; «2026-11» = solo para la factura de ese mes
        Schema::table('facturacion_contactos', function (Blueprint $table) {
            $table->string('mes', 7)->nullable()->after('sociedad');
            $table->dropUnique(['sociedad']);
            $table->unique(['sociedad', 'mes']);
        });
    }

    public function down(): void
    {
        Schema::table('facturacion_contactos', function (Blueprint $table) {
            $table->dropUnique(['sociedad', 'mes']);
            $table->dropColumn('mes');
            $table->unique('sociedad');
        });
    }
};
