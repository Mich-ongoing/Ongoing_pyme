<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // El valor cifrado ocupa más de 255 caracteres.
        Schema::table('panel_credenciales', function (Blueprint $table) {
            $table->text('client_secret')->change();
        });

        foreach (DB::table('panel_credenciales')->get(['id', 'client_secret']) as $fila) {
            DB::table('panel_credenciales')->where('id', $fila->id)
                ->update(['client_secret' => Crypt::encryptString($fila->client_secret)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('panel_credenciales')->get(['id', 'client_secret']) as $fila) {
            DB::table('panel_credenciales')->where('id', $fila->id)
                ->update(['client_secret' => Crypt::decryptString($fila->client_secret)]);
        }
    }
};
