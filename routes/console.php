<?php

use App\Webminder\Panel\Jobs\ActualizarSitio;
use App\Webminder\Panel\Models\Ejecucion;
use App\Webminder\Panel\Models\SitioWeb;
use App\Webminder\Panel\Services\AgenteComunicacionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Sleep;

$avisar = function (string $asunto, string $texto): void {
    $para = config('services.avisos.email');
    if (!$para) {
        Log::warning("watchWP: falta WEBMINDER_AVISOS_EMAIL, no se envía «{$asunto}»");

        return;
    }
    try {
        Mail::raw($texto, fn ($m) => $m->to($para)->subject("watchWP · {$asunto}"));
    } catch (\Throwable $e) {
        // Un correo que falla no debe cortar la vigilancia del resto de sitios
        Log::error("watchWP: no se pudo enviar «{$asunto}»: {$e->getMessage()}");
    }
};

// Hora de España en los correos (la app guarda en UTC)
$hora = fn ($fecha) => $fecha->copy()->timezone('Europe/Madrid')->format('d/m/Y H:i');

Artisan::command('webminder:verificar', function (AgenteComunicacionService $agente) {
    foreach (SitioWeb::where('activo', true)->whereHas('credencial', fn ($q) => $q->where('activa', true))->get() as $sitio) {
        $r = $agente->obtenerStatus($sitio);
        $this->line(($r['success'] ? 'ok    ' : 'FALLO ') . $sitio->nombre . ($r['success'] ? '' : ' — ' . $r['error']));
    }
})->purpose('Consulta el estado de todos los sitios vinculados');

/**
 * Caídas, como Jetpack: la portada (sin caché) no responde o da 5xx dos veces
 * seguidas → un aviso; cuando vuelve, otro con el tiempo caída.
 */
Artisan::command('webminder:vigilar', function () use ($avisar, $hora) {
    foreach (SitioWeb::where('activo', true)->get() as $sitio) {
        $url = $sitio->url . (str_contains($sitio->url, '?') ? '&' : '?') . 'watchwp=' . time();
        for ($intento = 1; ; $intento++) {
            try {
                $r = Http::timeout(30)->withUserAgent('watchWP uptime')->get($url);
                $fallo = $r->serverError() ? "HTTP {$r->status()}" : null;
            } catch (\Throwable $e) {
                $fallo = $e->getMessage();
            }
            if (!$fallo || $intento === 2) {
                break;
            }
            Sleep::for(30)->seconds();
        }

        if ($fallo && !$sitio->caida_desde) {
            $sitio->update(['caida_desde' => now()]);
            $avisar("🔴 {$sitio->nombre} está caída", "{$sitio->url} no responde desde las {$hora(now())}.\n\nMotivo: {$fallo}");
        } elseif (!$fallo && $sitio->caida_desde) {
            $minutos = (int) $sitio->caida_desde->diffInMinutes(now());
            $avisar("🟢 {$sitio->nombre} vuelve a funcionar", "{$sitio->url} responde de nuevo. Ha estado caída unos {$minutos} min (desde las {$hora($sitio->caida_desde)}).");
            $sitio->update(['caida_desde' => null]);
        }
        $this->line(($fallo ? 'CAÍDA ' : 'ok    ') . $sitio->nombre . ($fallo ? " — {$fallo}" : ''));
    }
})->purpose('Comprueba si las webs responden y avisa por correo de caídas y recuperaciones');

/**
 * Actualiza plugins y temas de todos los sitios, uno tras otro (copia de BD,
 * rollback y comparación visual como un lote manual) y manda un informe al final.
 */
Artisan::command('webminder:nocturna', function (AgenteComunicacionService $agente) use ($avisar, $hora) {
    $estados = [
        'ok' => 'actualizado', 'ok_sin_verificar' => 'actualizado (sin verificar)',
        'cambio_visual' => '⚠ la web se ve distinta, revisa las capturas', 'revertido' => '✖ falló y se revirtió',
        'licencia' => '✖ necesita licencia', 'error' => '✖ error', 'omitido' => 'omitido', 'pendiente' => 'no se llegó a ejecutar',
    ];
    $informe = [];
    $sitios = SitioWeb::where('activo', true)->whereHas('credencial', fn ($q) => $q->where('activa', true))->orderBy('nombre')->get();

    foreach ($sitios as $sitio) {
        $r = $agente->obtenerActualizaciones($sitio, true);
        if (!$r['success']) {
            $informe[] = "✖ {$sitio->nombre}: no se pudo leer qué hay pendiente — {$r['error']}";
            continue;
        }
        $items = collect($r['data'] ?? [])->whereIn('type', ['plugin', 'theme'])->map(Ejecucion::item(...))->values()->all();
        if (!$items) {
            $informe[] = "✔ {$sitio->nombre}: todo al día";
            continue;
        }

        $ejecucion = Ejecucion::create(['sitio_id' => $sitio->id, 'copia_bd' => true, 'items' => $items]);
        try {
            ActualizarSitio::dispatchSync($ejecucion->id);
        } catch (\Throwable $e) {
            // failed() ya marca el lote como interrumpido; se sigue con el resto de sitios
            report($e);
        }
        $ejecucion->refresh();
        if ($ejecucion->estado === 'pendiente') {
            // WithoutOverlapping lo soltó: había un lote manual en marcha en ese sitio
            $ejecucion->update(['estado' => 'interrumpida', 'error' => 'Había otro lote en curso en el sitio; esta noche no se ha actualizado.', 'terminada_en' => now()]);
        }

        $bloque = ($ejecucion->estado === 'completada' ? '✔' : '✖') . " {$sitio->nombre}: " . str_replace('_', ' ', $ejecucion->estado)
            . ($ejecucion->error ? " — {$ejecucion->error}" : '');
        foreach ($ejecucion->items as $item) {
            $bloque .= "\n    · {$item['nombre']} {$item['desde']} → {$item['hasta']}: " . ($estados[$item['estado']] ?? $item['estado'])
                . (!in_array($item['estado'], ['ok', 'pendiente'], true) && $item['mensaje'] ? " ({$item['mensaje']})" : '');
        }
        $informe[] = $bloque . "\n    " . route('webminder-panel.ejecuciones.show', $ejecucion);
        $this->line(strtok($bloque, "\n"));
    }

    $caidas = SitioWeb::where('activo', true)->whereNotNull('caida_desde')->get()->toBase()
        ->map(fn ($s) => "🔴 {$s->nombre} sigue caída desde las {$hora($s->caida_desde)}");
    $avisar('Informe de mantenimiento ' . now()->timezone('Europe/Madrid')->format('d/m/Y'),
        $caidas->merge($informe)->implode("\n\n") ?: 'No hay sitios vinculados.');
})->purpose('Actualiza plugins y temas de todos los sitios y envía el informe por correo');

Artisan::command('webminder:worker-zip', function () {
    $origen = base_path('webminder-worker');
    preg_match("/const VERSION = '([^']+)'/", File::get($origen . '/webminder-worker.php'), $m);
    $version = $m[1] ?? throw new RuntimeException('No se encontró VERSION en el worker');
    $destino = storage_path('app/worker');
    File::ensureDirectoryExists($destino);

    $zip = new ZipArchive();
    $zip->open($destino . '/webminder-worker.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach (File::allFiles($origen) as $archivo) {
        $zip->addFile($archivo->getPathname(), 'webminder-worker/' . $archivo->getRelativePathname());
    }
    $zip->close();

    $base = rtrim(config('app.url'), '/') . '/webminder/worker/';
    File::put($destino . '/manifest.json', json_encode([
        'version' => $version,
        'download_url' => $base . 'webminder-worker.zip',
        'requires' => '5.6',
        'requires_php' => '7.4',
        'last_updated' => now()->toDateTimeString(),
        'sections' => ['changelog' => 'Versión ' . $version],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $this->info("Worker {$version} → {$destino}/webminder-worker.zip (manifiesto en {$base}manifest.json)");
})->purpose('Empaqueta el worker y publica su manifiesto de autoactualización');

// Plesk: un solo cron "* * * * * php artisan schedule:run"
Schedule::command('queue:work --stop-when-empty --max-time=3500 --timeout=3600')->everyMinute()->withoutOverlapping(70)->runInBackground();
Schedule::command('webminder:verificar')->hourly()->withoutOverlapping();
Schedule::command('webminder:vigilar')->everyFiveMinutes()->withoutOverlapping(30)->runInBackground();
Schedule::command('webminder:nocturna')->dailyAt('23:00')->timezone('Europe/Madrid')->withoutOverlapping(720)->runInBackground();
// Capturas de la comparación visual: 30 días (las copias para revertir en el sitio duran 7)
Schedule::call(fn () => \App\Webminder\Panel\Models\Ejecucion::where('created_at', '<', now()->subDays(30))->pluck('id')
    ->each(fn ($id) => \Illuminate\Support\Facades\Storage::disk('local')->deleteDirectory(\App\Webminder\Panel\Services\CapturasService::ruta($id))))->daily();
