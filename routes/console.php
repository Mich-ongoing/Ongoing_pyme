<?php

use App\Webminder\Panel\Models\SitioWeb;
use App\Webminder\Panel\Services\AgenteComunicacionService;
use App\Webminder\Panel\Services\AvisosService;
use App\Webminder\Panel\Services\CerrojoService;
use App\Webminder\Panel\Services\InjertoService;
use App\Webminder\Panel\Services\RondaNocturna;
use App\Webminder\Panel\Services\VulnerabilidadesService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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
        // Responde 200 pero no es la web: en ccmir-mir.com y delvy.es 1 de cada 3 visitas acababa en el login de
        // Plesk (Cloudflare entraba por una IPv6 del servidor sin la web) y nada avisaba. Un correo al día como mucho.
        $final = $fallo ? '' : (string) $r->effectiveUri();
        $propio = fn ($u) => preg_replace('/^www\./', '', strtolower((string) parse_url((string) $u, PHP_URL_HOST)));
        if (!$fallo && $r->status() === 200 && $sitio->estado !== 'mantenimiento'
            && (!preg_match('#/wp-(content|includes)/#', $r->body()) || ($final !== '' && $propio($final) !== $propio($sitio->url)))
            && Cache::add("watchwp-no-es-la-web-{$sitio->id}", true, now()->addDay())) {
            preg_match('#<title[^>]*>(.*?)</title>#is', $r->body(), $titulo);
            AvisosService::enviar("⚠ {$sitio->nombre} no siempre sirve la web",
                "Al pedir {$sitio->url} llegó otra cosa en vez de la web de WordPress:\n\n  URL final: " . ($final ?: $url)
                . "\n  Título: " . trim(html_entity_decode(strip_tags($titulo[1] ?? '(sin título)'))) . "\n\n"
                . "Si es una página de Plesk (login o 404), lo habitual es que en el DNS de Cloudflare haya un registro AAAA (IPv6) "
                . "que apunta a una IP en la que la web no está configurada: bórralo o asígnale esa IPv6 en Plesk. Afecta también a los visitantes.");
        }
        if (!$fallo && ($motivo = CerrojoService::falsoCloudflare($r->body())) && Cache::add("watchwp-falso-cloudflare-{$sitio->id}", true, now()->addDay())) {
            AvisosService::enviar("🚨 {$sitio->nombre} muestra un falso Cloudflare a los visitantes",
                "La portada de {$sitio->url} lleva código malicioso ({$motivo}).\n\n"
                . "Es el ataque «ClickFix»: enseña una verificación falsa de Cloudflare y pide pulsar Win+R, Ctrl+V y Enter, "
                . "lo que ejecuta un programa malicioso en el ordenador del visitante. A los administradores conectados no se lo enseña.\n\n"
                . "Qué hacer: actualizar Cerrojo y pulsar «Limpiar» en watchWP (sección Cerrojo); si sigue, buscar el código en "
                . "Code Snippets, Header Footer Code Manager, el tema y los plugins nulled. Cambiar contraseñas de los administradores.");
        }
        // Webs con Injerto: dominios de los scripts de la portada (no páginas de error ni retos de Cloudflare)
        if (!$fallo && $sitio->tiene_injerto && $r->status() === 200) {
            $injerto->revisarScripts($sitio, $r->body(), (string) $r->effectiveUri());
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
    $texto = 'Si lees esto, los avisos de watchWP llegan bien.';
    \Illuminate\Support\Facades\Mail::raw($texto, fn ($m) => $m->to(config('services.avisos.email'))->subject('watchWP · Correo de prueba')
        ->html(view('webminder-panel.correos.aviso', ['asunto' => 'Correo de prueba', 'texto' => $texto])->render()));
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

Artisan::command('aligera:publicar {zip} {--cambios= : Qué trae esta versión}', function (string $zip) {
    // El zip de construir.py: dentro, aligera-pro/aligera.php con la cabecera «Version:»
    $z = new ZipArchive();
    $z->open($zip) === true || throw new RuntimeException("No se puede abrir {$zip}");
    $cabecera = $z->getFromName('aligera-pro/aligera.php');
    $z->close();
    preg_match('/^ \* Version:\s*(\S+)/m', (string) $cabecera, $m);
    $version = $m[1] ?? throw new RuntimeException('El zip no tiene aligera-pro/aligera.php con «Version:» (¿es la edición gratuita?)');
    preg_match('/^ \* Requires at least:\s*(\S+)/m', $cabecera, $wp);
    preg_match('/^ \* Requires PHP:\s*(\S+)/m', $cabecera, $php);

    $destino = storage_path('app/aligera');
    File::ensureDirectoryExists($destino);
    File::copy($zip, $destino . '/aligera-pro.zip');
    File::put($destino . '/manifest.json', json_encode([
        'version' => $version,
        'requires' => $wp[1] ?? '6.0',
        'requires_php' => $php[1] ?? '7.4',
        'last_updated' => now()->toDateTimeString(),
        'sections' => ['changelog' => $this->option('cambios') ?: 'Versión ' . $version],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $this->info("Aligera Pro {$version} publicada: las webs con licencia la verán en sus actualizaciones (en 12 h como mucho).");
})->purpose('Publica una versión de Aligera Pro para las actualizaciones automáticas');

/** Cruza el feed de Wordfence con lo instalado y avisa (una vez) de cada fallo nuevo que afecte a una web. */
Artisan::command('webminder:vulnerabilidades', function (VulnerabilidadesService $vulns) {
    try {
        $this->info($vulns->actualizar() . ' fallos conocidos afectan a algo instalado en alguna versión.');
    } catch (\Throwable $e) {
        $this->error($e->getMessage());

        return 1;
    }
    // Agrupado por web y componente: una línea por cosa que actualizar, no una por fallo
    $bloques = [];
    $total = 0;
    $webs = 0;
    foreach (SitioWeb::where('activo', true)->orderBy('nombre')->get() as $sitio) {
        $porComponente = [];
        foreach ($vulns->deSitio($sitio) as $v) {
            if (Cache::add("watchwp-vuln-{$sitio->id}-{$v['id']}-{$v['clave']}", true, now()->addYear())) {
                $porComponente["{$v['tipo']} {$v['nombre']} {$v['version']}"][] = $v;
            }
        }
        $webs += $porComponente ? 1 : 0;
        foreach ($porComponente as $componente => $vs) {
            $total += count($vs);
            $arreglos = array_merge(...array_column($vs, 'arreglado'));
            usort($arreglos, 'version_compare');
            $sinArreglo = count(array_filter($vs, fn ($v) => !$v['arreglado']));
            $accion = $arreglos ? 'actualizar a la ' . end($arreglos) . ' o superior' : 'SIN ARREGLO publicado: valora desactivarlo';
            if ($arreglos && $sinArreglo) {
                $accion .= " ({$sinArreglo} sin arreglo aún)";
            }
            $bloques[] = "{$sitio->nombre} · {$componente} → {$accion}\n" . implode("\n", array_map(fn ($v) => "  - {$v['titulo']}", $vs));
        }
    }
    if ($bloques) {
        AvisosService::enviar("⚠ {$total} fallos de seguridad: " . count($bloques) . " cosas que actualizar en {$webs} webs", implode("\n\n", $bloques)
            . "\n\nEl detalle y los enlaces de Wordfence están en la ficha de cada web en watchWP. Lo que tenga versión con el arreglo lo actualiza la ronda nocturna, salvo plugins y temas de pago sin licencia activa: esos hay que subirlos a mano.");
    }
    $this->info(count($bloques) . ' avisos nuevos.');
})->purpose('Avisa de plugins, temas o núcleo con fallos de seguridad conocidos (Wordfence Intelligence)');

/**
 * Archivos que nunca deberían poder descargarse (contraseñas de la BD, código, errores). Se mira el contenido
 * y no solo el 200: muchas webs responden 200 con la portada a cualquier ruta.
 */
Artisan::command('webminder:expuestos', function () {
    $wpConfig = '/DB_PASSWORD/';
    $rutas = [
        '.env' => '/^\s*(APP_KEY|DB_PASSWORD|DB_HOST)\s*=/m', '.git/HEAD' => '/^(ref: refs\/|[0-9a-f]{40}\s*$)/',
        'wp-content/debug.log' => '/PHP (Fatal|Parse|Warning|Notice|Deprecated)/', 'debug.log' => '/PHP (Fatal|Parse|Warning|Notice|Deprecated)/',
        'wp-config.php.bak' => $wpConfig, 'wp-config.php.old' => $wpConfig, 'wp-config.php.save' => $wpConfig,
        'wp-config.php~' => $wpConfig, 'wp-config.bak' => $wpConfig, 'wp-config.txt' => $wpConfig,
        'backup.sql' => '/(CREATE TABLE|INSERT INTO)/', 'database.sql' => '/(CREATE TABLE|INSERT INTO)/', 'dump.sql' => '/(CREATE TABLE|INSERT INTO)/',
    ];
    $lineas = [];
    foreach (SitioWeb::where('activo', true)->orderBy('nombre')->get() as $sitio) {
        foreach ($rutas as $ruta => $firma) {
            $url = rtrim($sitio->url, '/') . '/' . $ruta;
            try {
                // Solo los primeros KB: un debug.log puede pesar cientos de MB
                $r = Http::timeout(15)->withoutRedirecting()->withUserAgent('watchWP')->withOptions(['stream' => true])->get($url);
                $inicio = $r->successful() ? $r->toPsrResponse()->getBody()->read(8192) : '';
            } catch (\Throwable) {
                continue;
            }
            if (preg_match($firma, $inicio) && Cache::add("watchwp-expuesto-{$sitio->id}-{$ruta}", true, now()->addDays(30))) {
                $lineas[] = "{$sitio->nombre}: {$url}";
            }
        }
    }
    if ($lineas) {
        AvisosService::enviar('⚠ Archivos privados descargables en ' . count($lineas) . ' URL', implode("\n", $lineas)
            . "\n\nCualquiera puede bajarlos. Bórralos (o muévelos fuera de la web) y, si es un wp-config, un .env o un .sql, cambia la contraseña de la base de datos.");
    }
    $this->info(count($lineas) . ' avisos nuevos.');
})->purpose('Avisa de .env, .git, debug.log, copias de wp-config o volcados .sql descargables');

// Plesk: un solo cron "* * * * * php artisan schedule:run"
Schedule::command('queue:work --stop-when-empty --max-time=3500 --timeout=3600')->everyMinute()->withoutOverlapping(70)->runInBackground();
Schedule::command('webminder:verificar')->hourly()->withoutOverlapping();
Schedule::command('webminder:vigilar')->everyFiveMinutes()->withoutOverlapping(30)->runInBackground();
Schedule::command('webminder:nocturna')->dailyAt('23:00')->timezone('Europe/Madrid')->withoutOverlapping(30);
// Después de la ronda: lo que siga vulnerable es lo que no se pudo actualizar solo
Schedule::command('webminder:vulnerabilidades')->dailyAt('07:00')->timezone('Europe/Madrid')->withoutOverlapping(60)
    ->when(fn () => (bool) config('services.wordfence.clave'));
Schedule::command('webminder:expuestos')->dailyAt('07:30')->timezone('Europe/Madrid')->withoutOverlapping(60)->runInBackground();
// Capturas de la comparación visual: 30 días (las copias para revertir en el sitio duran 7)
Schedule::call(fn () => \App\Webminder\Panel\Models\Ejecucion::where('created_at', '<', now()->subDays(30))->pluck('id')
    ->each(fn ($id) => \Illuminate\Support\Facades\Storage::disk('local')->deleteDirectory(\App\Webminder\Panel\Services\CapturasService::ruta($id))))->daily();
