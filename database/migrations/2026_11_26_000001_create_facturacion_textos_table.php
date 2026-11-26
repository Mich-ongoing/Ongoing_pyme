<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Texto impreso en la factura que se repite en todas las de la sociedad ese año
        Schema::create('facturacion_textos', function (Blueprint $table) {
            $table->id();
            $table->string('sociedad');
            $table->unsignedSmallInteger('anio');
            $table->text('texto');
            $table->timestamps();
            $table->unique(['sociedad', 'anio']);
        });
        // Lo que se mandó a Holded, para verlo en las ya creadas
        Schema::table('facturacion_borradores', function (Blueprint $table) {
            $table->text('texto')->nullable()->after('lineas');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facturacion_textos');
        Schema::table('facturacion_borradores', function (Blueprint $table) {
            $table->dropColumn('texto');
        });
    }
};
