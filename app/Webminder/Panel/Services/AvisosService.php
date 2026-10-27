<?php

namespace App\Webminder\Panel\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** Correos de watchWP: caídas, lotes cortados e informe nocturno. */
class AvisosService
{
    /** $html opcional: el texto va igual como alternativa para clientes sin HTML. */
    public static function enviar(string $asunto, string $texto, ?string $html = null): void
    {
        $para = config('services.avisos.email');
        if (!$para) {
            Log::warning("watchWP: falta WEBMINDER_AVISOS_EMAIL, no se envía «{$asunto}»");

            return;
        }
        try {
            Mail::raw($texto, function ($m) use ($para, $asunto, $html) {
                $m->to($para)->subject("watchWP · {$asunto}");
                if ($html) {
                    $m->html($html);
                }
            });
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
