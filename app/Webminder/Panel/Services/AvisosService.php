<?php

namespace App\Webminder\Panel\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** Correos de watchWP: caídas, lotes cortados e informe nocturno. */
class AvisosService
{
    /** Sin $html se maqueta el texto con la plantilla de aviso; el texto va siempre como alternativa. */
    public static function enviar(string $asunto, string $texto, ?string $html = null): void
    {
        $para = config('services.avisos.email');
        if (!$para) {
            Log::warning("watchWP: falta WEBMINDER_AVISOS_EMAIL, no se envía «{$asunto}»");

            return;
        }
        try {
            $html ??= view('webminder-panel.correos.aviso', ['asunto' => $asunto, 'texto' => $texto])->render();
        } catch (\Throwable $e) {
            // Si falla la maqueta el aviso sale igual, en texto
            Log::error("watchWP: no se pudo maquetar «{$asunto}»: {$e->getMessage()}");
            $html = null;
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
