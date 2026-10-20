<?php

use App\Webminder\Panel\Models\SitioWeb;
use App\Webminder\Panel\Services\AgenteComunicacionService;
use App\Webminder\Panel\Services\AvisosService;
use App\Webminder\Panel\Services\InjertoService;
use App\Webminder\Panel\Services\RondaNocturna;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Sleep;

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
Artisan::command('webminder:vigilar', function (RondaNocturna $ronda, InjertoService $injerto) {
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
            AvisosService::enviar("🔴 {$sitio->nombre} está caída", "{$sitio->url} no responde desde las " . AvisosService::hora(now()) . ".\n\nMotivo: {$fallo}");
        } elseif (!$fallo && $sitio->caida_desde) {
            $minutos = (int) $sitio->caida_desde->diffInMinutes(now());
            AvisosService::enviar("🟢 {$sitio->nombre} vuelve a funcionar", "{$sitio->url} responde de nuevo. Ha estado caída unos {$minutos} min (desde las " . AvisosService::hora($sitio->caida_desde) . ").");
            $sitio->update(['caida_desde' => null]);
        }
        // Webs con Injerto: dominios de los scripts de la portada (no páginas de error ni retos de Cloudflare)
        if (!$fallo && $sitio->tiene_injerto && $r->status() === 200) {
            $injerto->revisarScripts($sitio, $r->body());
        }
        $this->line(($fallo ? 'CAÍDA ' : 'ok    ') . $sitio->nombre . ($fallo ? " — {$fallo}" : ''));
    }
    $ronda->rescatar();
})->purpose('Avisa de caídas y recuperaciones, y rescata lotes de actualización atascados');

// Núcleo, plugins, temas y traducciones de todos los sitios, de uno en uno; informe al acabar
Artisan::command('webminder:nocturna', function (RondaNocturna $ronda) {
    $this->info('Ronda ' . $ronda->planificar() . ' en marcha (la hace la cola, por pasos)');
})->purpose('Lanza la actualización nocturna de todos los sitios');

Artisan::command('webminder:probar-correo', function () {
    config(['services.avisos.email' => config('services.avisos.email') ?: throw new RuntimeException('Falta WEBMINDER_AVISOS_EMAIL en el .env')]);
    \Illuminate\Support\Facades\Mail::raw('Si lees esto, los avisos de watchWP llegan bien.', fn ($m) => $m->to(config('services.avisos.email'))->subject('watchWP · Correo de prueba'));
    $this->info('Enviado a ' . config('services.avisos.email'));
})->purpose('Manda un correo de prueba (sin capturar errores, para ver qué falla)');

Artisan::command('webminder:injerto-claves', function () {
    $par = sodium_crypto_sign_keypair();
    $this->line('Pública (constante INJERTO_CLAVE_PUBLICA del plugin Injerto):');
    $this->info(base64_encode(sodium_crypto_sign_publickey($par)));
    $this->line('Privada (WEBMINDER_INJERTO_CLAVE en el .env del panel; guárdala también fuera del servidor):');
    $this->warn(base64_encode(sodium_crypto_sign_secretkey($par)));
})->purpose('Genera el par de claves con que el panel firma el código de Injerto');

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
Schedule::command('webminder:nocturna')->dailyAt('23:00')->timezone('Europe/Madrid')->withoutOverlapping(30);
// Capturas de la comparación visual: 30 días (las copias para revertir en el sitio duran 7)
Schedule::call(fn () => \App\Webminder\Panel\Models\Ejecucion::where('created_at', '<', now()->subDays(30))->pluck('id')
    ->each(fn ($id) => \Illuminate\Support\Facades\Storage::disk('local')->deleteDirectory(\App\Webminder\Panel\Services\CapturasService::ruta($id))))->daily();
