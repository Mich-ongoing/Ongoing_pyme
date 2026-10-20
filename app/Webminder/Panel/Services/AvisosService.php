<?php

namespace App\Webminder\Panel\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** Correos de watchWP: caídas, lotes cortados e informe nocturno. */
class AvisosService
{
    public static function enviar(string $asunto, string $texto): void
    {
        $para = config('services.avisos.email');
        if (!$para) {
            Log::warning("watchWP: falta WEBMINDER_AVISOS_EMAIL, no se envía «{$asunto}»");

            return;
        }
        try {
            Mail::raw($texto, fn ($m) => $m->to($para)->subject("watchWP · {$asunto}"));
        } catch (\Throwable $e) {
            // Un correo que falla no debe cortar la vigilancia ni las actualizaciones
            Log::error("watchWP: no se pudo enviar «{$asunto}»: {$e->getMessage()}");
        }
    }

    /** Hora de España en los correos (la app guarda en UTC). */
    public static function hora(\DateTimeInterface $fecha): string
    {
        return \Illuminate\Support\Carbon::instance($fecha)->timezone('Europe/Madrid')->format('d/m/Y H:i');
    }
}
