<?php

use App\Webminder\Panel\Models\SitioWeb;
use App\Webminder\Panel\Services\AgenteComunicacionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schedule;

Artisan::command('webminder:verificar', function (AgenteComunicacionService $agente) {
    foreach (SitioWeb::where('activo', true)->whereHas('credencial', fn ($q) => $q->where('activa', true))->get() as $sitio) {
        $r = $agente->obtenerStatus($sitio);
        $this->line(($r['success'] ? 'ok    ' : 'FALLO ') . $sitio->nombre . ($r['success'] ? '' : ' — ' . $r['error']));
    }
})->purpose('Consulta el estado de todos los sitios vinculados');

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
// Capturas de la comparación visual: 30 días (las copias para revertir en el sitio duran 7)
Schedule::call(fn () => \App\Webminder\Panel\Models\Ejecucion::where('created_at', '<', now()->subDays(30))->pluck('id')
    ->each(fn ($id) => \Illuminate\Support\Facades\Storage::disk('local')->deleteDirectory(\App\Webminder\Panel\Services\CapturasService::ruta($id))))->daily();
