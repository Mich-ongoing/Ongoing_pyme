<?php
/**
 * Autocomprobación de /cerrojo del worker (instalar Cerrojo firmado, permiso de instalar, limpiar),
 * en un WordPress con el worker activo y Cerrojo 1.5+ en mu-plugins:
 *   cp tests/worker/prueba-cerrojo.php <wp>/ && ddev wp eval-file prueba-cerrojo.php
 * Se relanza con un mu-plugin temporal que pone la clave de pruebas del panel (semilla 'p'×32,
 * la de los tests del panel) en el worker y en Cerrojo, y que no deja salir correos.
 * Necesita además CERROJO_14 con la ruta de un cerrojo.php 1.4.0 (para probar que no baja de versión).
 */

use Ongoing\WebminderWorker\WebminderAgent;

$mu = WPMU_PLUGIN_DIR . '/0-prueba-cerrojo-worker.php';
if (!defined('PRUEBA_CERROJO_WORKER')) {
    $publica = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(str_repeat('p', 32))));
    file_put_contents($mu, "<?php\ndefine('PRUEBA_CERROJO_WORKER', true);\ndefine('ONGOING_WEBMINDER_PANEL_KEY', '{$publica}');\ndefine('CERROJO_CLAVE_PANEL', '{$publica}');\nadd_filter('pre_wp_mail', '__return_true');\n");
    passthru('wp eval-file ' . escapeshellarg(__FILE__) . ' --path=' . escapeshellarg(ABSPATH), $codigo);
    unlink($mu);
    cerrojo_conocer(); // sin el mu-plugin de prueba
    exit($codigo);
}

$fallos = 0;
$ok = function ($cond, $msg) use (&$fallos) {
    echo ($cond ? "  ok  " : "  FALLO ") . $msg . "\n";
    if (!$cond) {
        $fallos++;
    }
};
cerrojo_conocer(); // el mu-plugin de prueba es nuevo: que Cerrojo no lo aparte a mitad

$id_orig = get_option(WebminderAgent::OPTION_CLIENT_ID);
$secreto_orig = get_option(WebminderAgent::OPTION_CLIENT_SECRET);
$secreto = 'sk_prueba_' . bin2hex(random_bytes(20));
update_option(WebminderAgent::OPTION_CLIENT_ID, 'webminder_test', false);
update_option(WebminderAgent::OPTION_CLIENT_SECRET, $secreto, false);
// Por HTTP real, como el panel: desde la CLI la OPcache de PHP-FPM no ve el archivo nuevo hasta 2 s después.
$peticion = function ($payload, $ruta = 'cerrojo') use ($secreto) {
    $cuerpo = json_encode($payload ?: new stdClass());
    $ts = time();
    $r = wp_remote_post(rest_url("webminder/v1/$ruta"), ['timeout' => 120, 'sslverify' => false, 'body' => $cuerpo, 'headers' => [
        'Content-Type' => 'application/json', 'X-Webminder-Timestamp' => (string) $ts,
        'X-Webminder-Signature-V2' => WebminderAgent::sign('POST', "/webminder/v1/$ruta", $ts, $cuerpo, $secreto),
    ]]);
    wp_cache_flush(); // lo que cambie la petición no está en la caché de este proceso
    return [(int) wp_remote_retrieve_response_code($r), json_decode((string) wp_remote_retrieve_body($r), true)];
};
$secreta = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(str_repeat('p', 32)));
$firmar = fn($codigo, $clave = null) => base64_encode(sodium_crypto_sign_detached('cerrojo|codigo|' . hash('sha256', $codigo), $clave ?: $secreta));

$archivo = WPMU_PLUGIN_DIR . '/cerrojo.php';
$actual = file_get_contents($archivo);
preg_match('/Version:\s*([0-9.]+)/', $actual, $m);
$version = $m[1];

echo "Instalar Cerrojo\n";
$otro = $actual . "\n// cambio\n";
[$st] = $peticion(['accion' => 'instalar', 'codigo' => $otro, 'firma' => $firmar($otro, sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))]);
$ok($st === 403 && file_get_contents($archivo) === $actual, 'código firmado con otra clave → 403 y no se toca');
[$st] = $peticion(['accion' => 'instalar', 'codigo' => $otro, 'firma' => $firmar($actual)]);
$ok($st === 403, 'firma de otro código → 403');
[$st, $d] = $peticion(['accion' => 'instalar', 'codigo' => $otro, 'firma' => $firmar($otro)]);
$ok($st === 200 && ($d['version'] ?? '') === $version && file_get_contents($archivo) === $otro, "código firmado por el panel → se instala ($version)");
$vieja = (string) @file_get_contents(getenv('CERROJO_14') ?: '/nada');
if ($vieja) {
    [$st] = $peticion(['accion' => 'instalar', 'codigo' => $vieja, 'firma' => $firmar($vieja)]);
    $ok($st === 409 && file_get_contents($archivo) === $otro, 'una versión más antigua, aunque esté firmada → 409 (no se baja de versión)');
} else {
    echo "  (sin CERROJO_14: no se prueba la bajada de versión)\n";
}
$rota = "<?php\n/**\n * Plugin Name: Cerrojo\n * Version: 99.0.0\n */\nthrow new Error('roto');\n";
[$st, $d] = $peticion(['accion' => 'instalar', 'codigo' => $rota, 'firma' => $firmar($rota)]);
$ok($st === 500 && file_get_contents($archivo) === $otro, 'una versión que rompe la web → vuelve sola a la anterior');
$ok(!file_exists($archivo . '.nuevo'), 'no deja temporales');
file_put_contents($archivo, $actual);

echo "Permiso de instalar plugins\n";
$hasta = time() + 1800;
[$st] = $peticion(['accion' => 'permiso', 'hasta' => $hasta, 'firma' => base64_encode(sodium_crypto_sign_detached(cerrojo_mensaje_permiso($hasta), sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())))]);
$ok($st === 403 && !cerrojo_puede_instalar(), 'permiso con otra firma → 403 y sigue cerrado');
[$st, $d] = $peticion(['accion' => 'permiso', 'hasta' => $hasta, 'firma' => base64_encode(sodium_crypto_sign_detached(cerrojo_mensaje_permiso($hasta), $secreta))]);
$ok($st === 200 && ($d['cerrojo']['permiso_hasta'] ?? 0) === $hasta && cerrojo_puede_instalar(), 'permiso firmado por el panel → abierto hasta la hora pedida');
[$st, $d] = $peticion(['accion' => 'permiso', 'hasta' => 0]);
$ok($st === 200 && ($d['cerrojo']['permiso_hasta'] ?? -9) === 0 && !cerrojo_puede_instalar(), 'cerrar no necesita firma');

echo "Limpiar y log\n";
update_option('widget_prueba_cerrojo', [2 => ['text' => '<p>a</p><script>var u=String.fromCharCode(104,116,116,112,115,58,47,47,101,118,105,108,46,101,120,97,109,112,108,101);</script>']]);
[$st, $d] = $peticion(['accion' => 'limpiar']);
$ok($st === 200 && (bool) preg_grep('/widget_prueba_cerrojo.*quitado/', $d['hechos'] ?? []) && get_option('widget_prueba_cerrojo')[2]['text'] === '<p>a</p>', 'limpiar quita lo inyectado en la BD y lo cuenta');
delete_option('widget_prueba_cerrojo');
global $wpdb;
$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE 'cerrojo\\_copia\\_%'");
$antes = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . \Ongoing\WebminderWorker\WebminderActivityLog::table_name() . " WHERE action = 'cerrojo_aviso'");
cerrojo_aviso('prueba', 'texto');
$ok((int) $wpdb->get_var("SELECT COUNT(*) FROM " . \Ongoing\WebminderWorker\WebminderActivityLog::table_name() . " WHERE action = 'cerrojo_aviso'") === $antes + 1, 'los avisos de Cerrojo llegan al log de actividad (crítico)');

[$st, $d] = $peticion([], 'status');
$ok($st === 200 && array_key_exists('permiso_hasta', $d['data']['cerrojo'] ?? []) && array_key_exists('apartados', $d['data']['cerrojo']), 'status incluye el permiso y lo apartado');

update_option(WebminderAgent::OPTION_CLIENT_ID, $id_orig, false);
update_option(WebminderAgent::OPTION_CLIENT_SECRET, $secreto_orig, false);
echo $fallos ? "\n$fallos FALLOS\n" : "\nTodo correcto\n";
exit($fallos ? 1 : 0);
