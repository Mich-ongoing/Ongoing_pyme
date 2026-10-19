<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un lote de actualizaciones sobre un sitio y el resultado de cada ítem.
        Schema::create('panel_ejecuciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sitio_id')->constrained('panel_sitios_web')->cascadeOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('estado', 20)->default('pendiente'); // pendiente|en_curso|completada|con_errores|interrumpida
            $table->boolean('copia_bd')->default(true);
            $table->string('archivo_copia_bd')->nullable();
            $table->json('items'); // [{tipo,item,nombre,desde,hasta,estado,mensaje}]
            $table->text('error')->nullable();
            $table->timestamp('iniciada_en')->nullable();
            $table->timestamp('terminada_en')->nullable();
            $table->timestamps();

            $table->index(['sitio_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_ejecuciones');
    }
};
