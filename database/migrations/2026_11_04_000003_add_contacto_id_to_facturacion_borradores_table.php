<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Contacto de Holded al que se hizo el borrador: aunque cambie el nombre en la hoja, no se repite en el mismo mes
        Schema::table('facturacion_borradores', function (Blueprint $table) {
            $table->string('contacto_id')->nullable()->after('sociedad');
            $table->index(['mes', 'contacto_id']);
        });
    }

    public function down(): void
    {
        Schema::table('facturacion_borradores', function (Blueprint $table) {
            $table->dropIndex(['mes', 'contacto_id']);
            $table->dropColumn('contacto_id');
        });
    }
};
