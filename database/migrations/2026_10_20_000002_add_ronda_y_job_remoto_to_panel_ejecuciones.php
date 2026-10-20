<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('panel_ejecuciones', function (Blueprint $table) {
            // Lotes de la actualización nocturna (se hacen de uno en uno y acaban en un informe)
            $table->string('ronda', 20)->nullable()->after('usuario_id')->index();
            // Trabajo en curso en el sitio: si el proceso del panel muere, el siguiente lo retoma
            $table->string('job_remoto', 64)->nullable()->after('progreso');
        });
    }

    public function down(): void
    {
        Schema::table('panel_ejecuciones', function (Blueprint $table) {
            $table->dropIndex(['ronda']);
            $table->dropColumn(['ronda', 'job_remoto']);
        });
    }
};
