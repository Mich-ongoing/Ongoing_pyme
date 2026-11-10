<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Licencias anuales de Aligera Pro: las crea el pago de Stripe (o el panel a mano)
        Schema::create('aligera_licencias', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 40)->unique();
            $table->string('email');
            $table->string('plan', 20); // personal|profesional|agencia
            $table->unsignedInteger('sitios_max');
            $table->timestamp('caduca_en');
            $table->string('stripe_suscripcion')->nullable()->unique();
            $table->string('stripe_cliente')->nullable();
            $table->string('nota')->nullable();
            $table->timestamps();
        });

        // Webs en las que se ha activado cada licencia (el dominio sin www)
        Schema::create('aligera_activaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('licencia_id')->constrained('aligera_licencias')->cascadeOnDelete();
            $table->string('dominio');
            $table->string('version', 20)->nullable();
            $table->timestamp('visto_en')->nullable();
            $table->timestamps();

            $table->unique(['licencia_id', 'dominio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aligera_activaciones');
        Schema::dropIfExists('aligera_licencias');
    }
};
