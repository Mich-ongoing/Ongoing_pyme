<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Contacto de Holded elegido a mano cuando el nombre de la hoja no coincide o coincide con varios
        Schema::create('facturacion_contactos', function (Blueprint $table) {
            $table->id();
            $table->string('sociedad')->unique();
            $table->string('holded_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facturacion_contactos');
    }
};
