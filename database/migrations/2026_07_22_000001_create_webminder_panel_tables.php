<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabla: Clientes
        Schema::create('panel_clientes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('empresa');
            $table->string('email')->unique();
            $table->string('telefono')->nullable();
            $table->string('ciudad')->nullable();
            $table->string('pais')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index('activo');
        });

        // Tabla: Sitios Web
        Schema::create('panel_sitios_web', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('panel_clientes')->onDelete('cascade');
            $table->string('nombre');
            $table->string('url')->unique();
            $table->text('descripcion')->nullable();
            $table->string('tecnologia')->nullable();
            $table->string('version_php')->nullable();
            $table->string('version_laravel')->nullable();
            $table->boolean('activo')->default(true);
            $table->enum('estado', ['online', 'offline', 'mantenimiento', 'sin_verificar'])->default('sin_verificar');
            $table->timestamp('ultima_verificacion')->nullable();
            $table->timestamp('proxima_verificacion')->nullable();
            $table->timestamps();

            $table->index(['cliente_id', 'estado']);
            $table->index('ultima_verificacion');
        });

        // Tabla: Credenciales
        Schema::create('panel_credenciales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sitio_id')->unique()->constrained('panel_sitios_web')->onDelete('cascade');
            $table->string('client_id')->unique();
            $table->string('client_secret');
            $table->boolean('activa')->default(true);
            $table->timestamp('ultima_utilizacion')->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();

            $table->index('client_id');
        });

        // Tabla: Estados (Histórico)
        Schema::create('panel_estados_sitio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sitio_id')->constrained('panel_sitios_web')->onDelete('cascade');
            $table->enum('estado', ['online', 'offline', 'mantenimiento', 'error'])->default('offline');
            $table->json('datos')->nullable();
            $table->json('metricas')->nullable();
            $table->unsignedSmallInteger('codigo_http')->nullable();
            $table->unsignedInteger('tiempo_respuesta_ms')->nullable();
            $table->timestamps();

            $table->index(['sitio_id', 'created_at']);
        });

        // Tabla: Auditoría
        Schema::create('panel_eventos_auditoria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('panel_clientes')->onDelete('cascade');
            $table->foreignId('sitio_id')->nullable()->constrained('panel_sitios_web')->onDelete('set null');
            $table->foreignId('usuario_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('accion');
            $table->string('descripcion');
            $table->json('detalles')->nullable();
            $table->enum('resultado', ['success', 'error', 'pending'])->default('pending');
            $table->ipAddress('ip_origen')->nullable();
            $table->timestamps();

            $table->index(['cliente_id', 'resultado']);
            $table->index(['sitio_id', 'created_at']);
            $table->index(['accion', 'resultado']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_eventos_auditoria');
        Schema::dropIfExists('panel_estados_sitio');
        Schema::dropIfExists('panel_credenciales');
        Schema::dropIfExists('panel_sitios_web');
        Schema::dropIfExists('panel_clientes');
    }
};
