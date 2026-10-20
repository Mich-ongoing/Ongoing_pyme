<?php
/**
 * Actualización real del núcleo con el worker (necesita red para wordpress.org).
 * Antes: ddev wp core download --version=<una anterior> --force --skip-content && ddev wp core update-db
 *   cp tests/worker/prueba-nucleo.php <wp>/ && ddev wp eval-file prueba-nucleo.php
 * Deja WordPress en la última versión.
 */

use Ongoing\WebminderWorker\WebminderAgent;

$fallos = 0;
$ok = function ($cond, $msg) use (&$fallos) {
    echo ($cond ? "  ok  " : "  FALLO ") . $msg . "\n";
    if (!$cond) {
        $fallos++;
    }
};

// Por HTTP contra el endpoint, como el panel (PHP-FPM real)
$secreto_orig = get_option(WebminderAgent::OPTION_CLIENT_SECRET);
$secreto = 'sk_prueba_' . bin2hex(random_bytes(20));
update_option(WebminderAgent::OPTION_CLIENT_SECRET, $secreto, false);
$llamar = function ($ruta, array $datos) use ($secreto) {
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

$antes = get_bloginfo('version');
$lista = $llamar('updates', ['refresh' => true]);
$core = array_values(array_filter($lista['data'] ?? [], function ($u) { return $u['type'] === 'core'; }));
$ok(count($core) === 1 && $lista['data'][0]['type'] === 'core', "la lista trae el núcleo el primero ({$antes} → " . ($core[0]['version'] ?? '?') . ')');

if ($core) {
    $r = $llamar('update', ['type' => 'core', 'item' => 'core']);
    $ok(!empty($r['success']), 'actualiza el núcleo: ' . ($r['message'] ?? $r['error'] ?? json_encode($r)));
    $ahora = trim(shell_exec('php -r ' . escapeshellarg('include "' . ABSPATH . 'wp-includes/version.php"; echo $wp_version;')));
    $ok($ahora === $core[0]['version'], "version.php dice {$ahora}");
    $ok(!file_exists(ABSPATH . '.maintenance'), 'no queda el modo mantenimiento');
    $ok(wp_remote_retrieve_response_code(wp_remote_get(home_url('/?nocache=' . time()), ['sslverify' => false])) === 200, 'la portada responde 200');

    $r = $llamar('update', ['type' => 'core', 'item' => 'core']);
    $ok(!empty($r['success']), 'repetirlo no hace nada: ' . ($r['message'] ?? ''));
}

update_option(WebminderAgent::OPTION_CLIENT_SECRET, $secreto_orig, false);
echo $fallos ? "\n{$fallos} FALLOS\n" : "\nTodo bien\n";
