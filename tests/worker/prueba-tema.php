<?php
/**
 * Reinstalar temas (worker 2.11; necesita red para wordpress.org). En una web con hello-elementor
 * (con actualización pendiente) y un tema hijo suyo:
 *   cp tests/worker/prueba-tema.php <wp>/ && ddev wp eval-file prueba-tema.php
 * 1) el tema de wordpress.org manipulado vuelve al código original, misma versión, y la update pendiente sigue
 * 2) un tema hijo / propio no se reinstala
 */

use Ongoing\WebminderWorker\WebminderAgent;

$fallos = 0;
$ok = function ($cond, $msg) use (&$fallos) {
    echo ($cond ? "  ok  " : "  FALLO ") . $msg . "\n";
    if (!$cond) {
        $fallos++;
    }
};
require_once ABSPATH . 'wp-admin/includes/update.php';

$secreto_orig = get_option(WebminderAgent::OPTION_CLIENT_SECRET);
$secreto = 'sk_prueba_' . bin2hex(random_bytes(20));
update_option(WebminderAgent::OPTION_CLIENT_SECRET, $secreto, false);
$pedir = function ($ruta, $datos) use ($secreto) {
    $cuerpo = json_encode($datos + ['_nonce' => bin2hex(random_bytes(8))]);
    $ts = time();
    $r = wp_remote_post(rest_url('webminder/v1/' . $ruta), [
        'timeout' => 300, 'sslverify' => false, 'body' => $cuerpo,
        'headers' => ['Content-Type' => 'application/json', 'X-Webminder-Timestamp' => (string) $ts,
            'X-Webminder-Signature-V2' => WebminderAgent::sign('POST', '/webminder/v1/' . $ruta, $ts, $cuerpo, $secreto)],
    ]);
    $d = json_decode(wp_remote_retrieve_body($r), true);
    return is_array($d) ? $d : ['success' => false, 'message' => 'HTTP ' . wp_remote_retrieve_response_code($r) . ': ' . substr(wp_remote_retrieve_body($r), 0, 300)];
};

try {
    $tema = 'hello-elementor';
    $hijo = get_stylesheet() !== $tema ? get_stylesheet() : null;
    wp_update_themes();
    $pendiente = get_site_transient('update_themes')->response[$tema]['new_version'] ?? null;
    $version = wp_get_theme($tema)->get('Version');
    $dir = get_theme_root() . '/' . $tema;
    file_put_contents($dir . '/functions.php', file_get_contents($dir . '/functions.php') . "\n// MANIPULADO\n");
    file_put_contents($dir . '/puerta.php', '<?php // puerta trasera');

    $s = $pedir('status', []);
    $lista = array_column($s['data']['themes'] ?? [], 'wporg', 'slug');
    $ok(($lista[$tema] ?? null) === true, "/status marca {$tema} como de wordpress.org");
    if ($hijo) {
        $ok(($lista[$hijo] ?? null) === false, "/status no marca el hijo {$hijo}");
    }

    $r = $pedir('update', ['type' => 'theme', 'item' => $tema, 'reinstall' => true]);
    clearstatcache();
    wp_clean_themes_cache(false);
    wp_cache_delete('alloptions', 'options'); // el transient lo ha reescrito la petición, no este proceso
    wp_cache_delete('notoptions', 'options');
    $ok(!empty($r['success']), 'reinstalar responde bien: ' . ($r['message'] ?? ''));
    $ok(!file_exists($dir . '/puerta.php'), 'el archivo añadido desaparece');
    $ok(strpos(file_get_contents($dir . '/functions.php'), 'MANIPULADO') === false, 'el archivo manipulado vuelve al original');
    $ok(wp_get_theme($tema)->get('Version') === $version, "sigue en la {$version}");
    $ok(($pendiente === null) || ((get_site_transient('update_themes')->response[$tema]['new_version'] ?? null) === $pendiente), "la actualización pendiente ({$pendiente}) sigue ahí");

    if ($hijo) {
        $r = $pedir('update', ['type' => 'theme', 'item' => $hijo, 'reinstall' => true]);
        $ok(empty($r['success']) && strpos($r['message'] ?? '', 'tema gratuito') !== false, 'el tema hijo no se reinstala: ' . ($r['message'] ?? ''));
    }
} finally {
    update_option(WebminderAgent::OPTION_CLIENT_SECRET, $secreto_orig, false);
}
echo $fallos ? "\n{$fallos} FALLO(S)\n" : "\nTodo bien\n";
