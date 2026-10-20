<?php
/**
 * Purga de copias caducadas en el refresco de /updates (el que hace la ronda nocturna):
 *   cp tests/worker/prueba-copias.php <wp>/ && ddev wp eval-file prueba-copias.php
 */

use Ongoing\WebminderWorker\WebminderAgent;

$fallos = 0;
$ok = function ($cond, $msg) use (&$fallos) {
    echo ($cond ? "  ok  " : "  FALLO ") . $msg . "\n";
    if (!$cond) {
        $fallos++;
    }
};

$dir = WebminderAgent::backup_dir();
$vieja = $dir . '/plugin-viejo-backup-' . (time() - 8 * DAY_IN_SECONDS);
$nueva = $dir . '/plugin-nuevo-backup-' . (time() - DAY_IN_SECONDS);
foreach ([$vieja, $nueva] as $d) {
    wp_mkdir_p($d);
    file_put_contents($d . '/plugin.php', str_repeat('x', 1000));
}

$secreto_orig = get_option(WebminderAgent::OPTION_CLIENT_SECRET);
$secreto = 'sk_prueba_' . bin2hex(random_bytes(20));
update_option(WebminderAgent::OPTION_CLIENT_SECRET, $secreto, false);
$cuerpo = json_encode(['refresh' => true, '_nonce' => bin2hex(random_bytes(8))]);
$ts = time();
$r = wp_remote_post(rest_url('webminder/v1/updates'), [
    'timeout' => 120, 'sslverify' => false, 'body' => $cuerpo,
    'headers' => ['Content-Type' => 'application/json', 'X-Webminder-Timestamp' => (string) $ts,
        'X-Webminder-Signature-V2' => WebminderAgent::sign('POST', '/webminder/v1/updates', $ts, $cuerpo, $secreto)],
]);
$d = json_decode(wp_remote_retrieve_body($r), true);
update_option(WebminderAgent::OPTION_CLIENT_SECRET, $secreto_orig, false);

$ok(!empty($d['success']), 'la lista responde');
$ok(!is_dir($vieja), 'borra la copia de hace 8 días');
$ok(is_dir($nueva), 'conserva la de ayer');
$ok(($d['backups_size'] ?? 0) >= 1000, 'informa del tamaño: ' . ($d['backups_size'] ?? 'nada') . ' bytes');

@unlink($nueva . '/plugin.php');
@rmdir($nueva);
echo $fallos ? "\n{$fallos} FALLOS\n" : "\nTodo bien\n";
