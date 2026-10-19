<?php
/**
 * Actualizaciones reales con el worker (necesita red para wordpress.org):
 *   cp tests/worker/prueba-actualizar.php <wp>/ && ddev wp eval-file prueba-actualizar.php
 * 1) plugin de wordpress.org en versión vieja → se actualiza
 * 2) plugin cuya versión nueva tumba la web → rollback a la anterior
 * 3) igual con un plugin de un solo archivo
 * Borra todo lo que crea al terminar.
 */

use Ongoing\WebminderWorker\WebminderAgent;

$fallos = 0;
$ok = function ($cond, $msg) use (&$fallos) {
    echo ($cond ? "  ok  " : "  FALLO ") . $msg . "\n";
    if (!$cond) {
        $fallos++;
    }
};
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

// Por HTTP contra el endpoint, como el panel: así corre en PHP-FPM (OPcache, buffers, timeouts reales)
$secreto_orig = get_option(WebminderAgent::OPTION_CLIENT_SECRET);
$secreto = 'sk_prueba_' . bin2hex(random_bytes(20));
update_option(WebminderAgent::OPTION_CLIENT_SECRET, $secreto, false);
$actualizar = function ($tipo, $item) use ($secreto) {
    $cuerpo = json_encode(['type' => $tipo, 'item' => $item, '_nonce' => bin2hex(random_bytes(8))]);
    $ts = time();
    $r = wp_remote_post(rest_url('webminder/v1/update'), [
        'timeout' => 300, 'sslverify' => false, 'body' => $cuerpo,
        'headers' => ['Content-Type' => 'application/json', 'X-Webminder-Timestamp' => (string) $ts,
            'X-Webminder-Signature-V2' => WebminderAgent::sign('POST', '/webminder/v1/update', $ts, $cuerpo, $secreto)],
    ]);
    $d = json_decode(wp_remote_retrieve_body($r), true);
    return is_array($d) ? $d : ['success' => false, 'message' => 'HTTP ' . wp_remote_retrieve_response_code($r) . ': ' . substr(wp_remote_retrieve_body($r), 0, 300)];
};
$version = function ($archivo) {
    wp_clean_plugins_cache(false);
    wp_cache_delete('alloptions', 'options');
    $d = get_plugin_data(WP_PLUGIN_DIR . '/' . $archivo, false, false);
    return $d['Version'];
};
// Paquete servido por el propio sitio y entrada falsa en el transient de updates
$publicar = function ($archivo_plugin, $slug, $zip_local, $nueva) {
    $up = wp_upload_dir();
    copy($zip_local, $up['basedir'] . '/' . basename($zip_local));
    $t = get_site_transient('update_plugins') ?: new stdClass();
    $t->response = isset($t->response) ? $t->response : [];
    $t->response[$archivo_plugin] = (object) ['slug' => $slug, 'plugin' => $archivo_plugin, 'new_version' => $nueva, 'package' => $up['baseurl'] . '/' . basename($zip_local)];
    set_site_transient('update_plugins', $t);
    return $up['basedir'] . '/' . basename($zip_local);
};
$zip = function ($destino, $archivos) {
    $z = new ZipArchive();
    $z->open($destino, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($archivos as $ruta => $contenido) {
        $z->addFromString($ruta, $contenido);
    }
    $z->close();
    return $destino;
};
$home_ok = function () {
    $r = wp_remote_get(add_query_arg('wm', wp_rand(), home_url('/')), ['sslverify' => false, 'timeout' => 20]);
    return wp_remote_retrieve_response_code($r) === 200;
};
$tmp = get_temp_dir();

echo "1) Plugin de wordpress.org\n";
WP_CLI::runcommand('plugin install classic-editor --version=1.6.5 --force --activate --quiet');
wp_cache_delete('alloptions', 'options');
$antes = $version('classic-editor/classic-editor.php');
$r = $actualizar('plugin', 'classic-editor/classic-editor.php');
$despues = $version('classic-editor/classic-editor.php');
$ok(!empty($r['success']) && version_compare($despues, $antes, '>'), "classic-editor $antes → $despues (" . ($r['message'] ?? '') . ')');
$ok(!empty($r['verified']), 'comprobado con las sondas (verified)');
wp_cache_delete('alloptions', 'options');
$ok(is_plugin_active('classic-editor/classic-editor.php'), 'sigue activo');
$ok(!glob(WebminderAgent::backup_dir() . '/classic-editor-backup-*'), 'la copia temporal se borró');
// En este proceso, no con runcommand: otro proceso dejaría aquí una copia vieja de active_plugins
wp_cache_delete('alloptions', 'options');
deactivate_plugins('classic-editor/classic-editor.php', true);
delete_plugins(['classic-editor/classic-editor.php']);

echo "2) Actualización que tumba la web → rollback\n";
$cabecera = "<?php\n/*\nPlugin Name: WM Roto\nVersion: %s\n*/\n";
wp_mkdir_p(WP_PLUGIN_DIR . '/wm-roto');
file_put_contents(WP_PLUGIN_DIR . '/wm-roto/wm-roto.php', sprintf($cabecera, '1.0') . "// versión buena\n");
file_put_contents(WP_PLUGIN_DIR . '/wm-roto/extra.txt', "archivo de la 1.0\n");
wp_cache_delete('alloptions', 'options');
activate_plugin('wm-roto/wm-roto.php');
$z = $zip($tmp . 'wm-roto-2.0.zip', ['wm-roto/wm-roto.php' => sprintf($cabecera, '2.0') . "if (!defined('REST_REQUEST')) { wm_funcion_que_no_existe(); }\n"]);
$publicado = $publicar('wm-roto/wm-roto.php', 'wm-roto', $z, '2.0');
$r = $actualizar('plugin', 'wm-roto/wm-roto.php');
$ok(empty($r['success']) && !empty($r['rolled_back']), 'detecta el fallo y restaura (' . ($r['message'] ?? '') . ')');
$ok($version('wm-roto/wm-roto.php') === '1.0' && is_file(WP_PLUGIN_DIR . '/wm-roto/extra.txt'), 'vuelve a la 1.0 con todos sus archivos');
wp_cache_delete('alloptions', 'options');
$ok(is_plugin_active('wm-roto/wm-roto.php'), 'y sigue activo');
$ok($home_ok(), 'la web responde 200');
deactivate_plugins('wm-roto/wm-roto.php', true);
delete_plugins(['wm-roto/wm-roto.php']);
@unlink($publicado);

echo "3) Plugin de un solo archivo → rollback\n";
file_put_contents(WP_PLUGIN_DIR . '/wm-solo.php', sprintf(str_replace('WM Roto', 'WM Solo', $cabecera), '1.0'));
wp_cache_delete('alloptions', 'options');
activate_plugin('wm-solo.php');
$z = $zip($tmp . 'wm-solo-2.0.zip', ['wm-solo.php' => sprintf(str_replace('WM Roto', 'WM Solo', $cabecera), '2.0') . "if (!defined('REST_REQUEST')) { wm_otra_que_no_existe(); }\n"]);
$publicado = $publicar('wm-solo.php', 'wm-solo', $z, '2.0');
$r = $actualizar('plugin', 'wm-solo.php');
$ok(empty($r['success']) && !empty($r['rolled_back']), 'detecta el fallo y restaura (' . ($r['message'] ?? '') . ')');
$ok($version('wm-solo.php') === '1.0', 'vuelve a la 1.0');
$ok($home_ok(), 'la web responde 200');
deactivate_plugins('wm-solo.php', true);
@unlink(WP_PLUGIN_DIR . '/wm-solo.php');
foreach (glob(WP_PLUGIN_DIR . '/wm-solo*', GLOB_ONLYDIR) as $d) {
    echo "  FALLO quedó la carpeta " . basename($d) . "\n";
    $fallos++;
}
@unlink($publicado);

update_option(WebminderAgent::OPTION_CLIENT_SECRET, $secreto_orig, false);
echo $fallos ? "\n$fallos FALLOS\n" : "\nTodo OK\n";
