<?php
/**
 * Renovar las claves secretas de wp-config.php (worker 2.10). HACER COPIA DE wp-config.php ANTES:
 *   cp tests/worker/prueba-claves.php <wp>/ && ddev wp eval-file prueba-claves.php
 * 1) cambia las 8 claves, el archivo sigue siendo válido y se cierran las sesiones
 * 2) la lista de Cerrojo se vuelve a firmar con las claves nuevas
 * 3) si falta una clave no se toca nada
 */

use Ongoing\WebminderWorker\WebminderAgent;

$fallos = 0;
$ok = function ($cond, $msg) use (&$fallos) {
    echo ($cond ? "  ok  " : "  FALLO ") . $msg . "\n";
    if (!$cond) {
        $fallos++;
    }
};
$secreto_orig = get_option(WebminderAgent::OPTION_CLIENT_SECRET);
$secreto = 'sk_prueba_' . bin2hex(random_bytes(20));
update_option(WebminderAgent::OPTION_CLIENT_SECRET, $secreto, false);
$pedir = function ($ruta, $datos) use ($secreto) {
    $cuerpo = json_encode($datos + ['_nonce' => bin2hex(random_bytes(8))]);
    $ts = time();
    $r = wp_remote_post(rest_url('webminder/v1/' . $ruta), [
        'timeout' => 60, 'sslverify' => false, 'body' => $cuerpo,
        'headers' => ['Content-Type' => 'application/json', 'X-Webminder-Timestamp' => (string) $ts,
            'X-Webminder-Signature-V2' => WebminderAgent::sign('POST', '/webminder/v1/' . $ruta, $ts, $cuerpo, $secreto)],
    ]);
    $d = json_decode(wp_remote_retrieve_body($r), true);
    return is_array($d) ? $d : ['success' => false, 'message' => 'HTTP ' . wp_remote_retrieve_response_code($r) . ': ' . substr(wp_remote_retrieve_body($r), 0, 300)];
};
$claves = function ($codigo) {
    preg_match_all("/define\\(\\s*'([A-Z_]+_(?:KEY|SALT))',\\s*'([^']*)'/", $codigo, $m);
    return array_combine($m[1], $m[2]);
};
$f = ABSPATH . 'wp-config.php';
$cerrojo_orig = get_option('cerrojo_plugins');

try {
    $antes = $claves(file_get_contents($f));
    $lista = ['plugins/akismet'];
    update_option('cerrojo_plugins', ['lista' => $lista, 'firma' => hash_hmac('sha256', implode("\n", $lista), AUTH_KEY . SECURE_AUTH_SALT)], true);
    $id = wp_insert_user(['user_login' => 'prueba_claves', 'user_pass' => 'Clave-123456', 'user_email' => 'prueba_claves@example.com', 'role' => 'editor']);
    WP_Session_Tokens::get_instance($id)->create(time() + 3600);

    $r = $pedir('sesiones', ['accion' => 'claves']);
    clearstatcache();
    $despues = $claves(file_get_contents($f));
    $ok(!empty($r['success']), 'responde bien: ' . ($r['message'] ?? ''));
    $ok(count($despues) >= 8 && count(array_intersect_assoc($antes, $despues)) === count($antes) - 8, 'las 8 claves cambian (y nada más)');
    $ok(strlen($despues['AUTH_KEY'] ?? '') === 64, 'clave nueva de 64 caracteres');
    exec('php -l ' . escapeshellarg($f), $o, $code);
    $ok($code === 0, 'wp-config.php sigue siendo PHP válido');
    $ok(!glob(ABSPATH . 'wp-config.php.webminder-*'), 'no queda ningún archivo temporal');
    wp_cache_delete('alloptions', 'options');
    $o = get_option('cerrojo_plugins');
    $ok(hash_equals(hash_hmac('sha256', implode("\n", $lista), $despues['AUTH_KEY'] . $despues['SECURE_AUTH_SALT']), (string) $o['firma']), 'Cerrojo: lista firmada con las claves nuevas');
    $st = $pedir('status', []);
    $ok(!empty($st['success']), 'la web sigue funcionando con las claves nuevas');
    $ok(!WP_Session_Tokens::get_instance($id)->get_all(), 'sesiones cerradas');

    // 3) sin una de las claves: no se escribe nada
    $codigo = file_get_contents($f);
    file_put_contents($f, preg_replace("/define\\(\\s*'NONCE_SALT'.*\\n/", '', $codigo, 1));
    $mtime = filemtime($f);
    $sin = file_get_contents($f);
    sleep(1);
    $r = $pedir('sesiones', ['accion' => 'claves']);
    clearstatcache();
    $ok(empty($r['success']) && strpos($r['message'] ?? '', 'NONCE_SALT') !== false && file_get_contents($f) === $sin, 'falta NONCE_SALT: error y wp-config intacto: ' . ($r['message'] ?? ''));
    file_put_contents($f, $codigo);
} finally {
    if (!empty($id)) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($id);
    }
    $cerrojo_orig === false ? delete_option('cerrojo_plugins') : update_option('cerrojo_plugins', $cerrojo_orig);
    update_option(WebminderAgent::OPTION_CLIENT_SECRET, $secreto_orig, false);
}
echo $fallos ? "\n$fallos FALLOS\n" : "\nTodo OK\n";
