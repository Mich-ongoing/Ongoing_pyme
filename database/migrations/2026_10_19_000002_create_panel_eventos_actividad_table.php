<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Log de actividad que empuja cada worker (logins, plugins, usuarios...).
        Schema::create('panel_eventos_actividad', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sitio_id')->constrained('panel_sitios_web')->cascadeOnDelete();
            $table->unsignedBigInteger('local_id');
            $table->timestamp('ocurrido_en');
            $table->string('actor_login')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('actor_rol', 100)->nullable();
            $table->string('ip', 64)->nullable();
            $table->string('accion', 100);
            $table->string('objeto_tipo', 50)->nullable();
            $table->string('objeto_nombre')->nullable();
            $table->string('severidad', 20)->default('info');
            $table->json('detalles')->nullable();
            $table->timestamps();

            // Idempotencia: el worker reenvía hasta recibir el acuse
            $table->unique(['sitio_id', 'local_id']);
            $table->index(['sitio_id', 'ocurrido_en']);
            $table->index('severidad');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_eventos_actividad');
    }
};
