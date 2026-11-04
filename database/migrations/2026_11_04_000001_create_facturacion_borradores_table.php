<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un borrador de Holded por sociedad y mes: impide crear dos veces la misma factura
        Schema::create('facturacion_borradores', function (Blueprint $table) {
            $table->id();
            $table->string('mes', 7); // 2026-11
            $table->string('sociedad');
            $table->string('holded_id');
            $table->decimal('total', 12, 2);
            $table->json('lineas');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['mes', 'sociedad']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facturacion_borradores');
    }
};
