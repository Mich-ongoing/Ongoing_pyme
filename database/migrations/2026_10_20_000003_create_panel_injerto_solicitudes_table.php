<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Código que Injerto pide publicar en una web; se aprueba aquí y se firma con la clave del panel
        Schema::create('panel_injerto_solicitudes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sitio_id')->constrained('panel_sitios_web')->cascadeOnDelete();
            $table->string('remoto_id', 32); // id de la solicitud en la web
            $table->unsignedBigInteger('ambito')->default(0); // 0 = toda la web; si no, ID de la página
            $table->string('ambito_nombre')->nullable();
            $table->json('valores');
            $table->json('anteriores')->nullable();
            $table->string('usuario')->nullable();
            $table->string('ip', 64)->nullable();
            $table->string('estado', 20)->default('pendiente'); // pendiente|aprobada|rechazada|fallida
            $table->text('error')->nullable();
            $table->foreignId('decidida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decidida_en')->nullable();
            $table->timestamps();

            $table->unique(['sitio_id', 'remoto_id']);
            $table->index('estado');
        });

        Schema::table('panel_sitios_web', function (Blueprint $table) {
            $table->boolean('tiene_injerto')->default(false)->after('caida_desde');
            // Dominios de scripts de la portada: {conocidos: [], avisados: []}
            $table->json('dominios_scripts')->nullable()->after('tiene_injerto');
        });
    }

    public function down(): void
    {
        Schema::table('panel_sitios_web', function (Blueprint $table) {
            $table->dropColumn(['tiene_injerto', 'dominios_scripts']);
        });
        Schema::dropIfExists('panel_injerto_solicitudes');
    }
};
