<?php
/**
 * Acciones de seguridad del worker 2.9 (necesita red para wordpress.org):
 *   ddev wp plugin install classic-editor --version=1.6.5 --activate
 *   cp tests/worker/prueba-seguridad.php <wp>/ && ddev wp eval-file prueba-seguridad.php
 * 1) reinstalar un plugin de wordpress.org manipulado → código original, misma versión, sigue activo
 * 2) un plugin propio no se reinstala
 * 3) /sesiones cerrar → fuera todas las sesiones
 * 4) /sesiones forzar_clave → no entra con la contraseña vieja hasta cambiarla
 * Borra lo que crea al terminar (menos classic-editor).
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
$fresco = function () {
    wp_cache_delete('alloptions', 'options');
    wp_cache_delete('notoptions', 'options');
    wp_clean_plugins_cache(false);
};

try {
    // 1) Reinstalar
    $ce = 'classic-editor/classic-editor.php';
    wp_update_plugins(); // el transient tiene que reconocerlo como de wordpress.org
    $ok(isset(get_site_transient('update_plugins')->response[$ce]), 'classic-editor 1.6.5 tiene actualización pendiente');
    $principal = WP_PLUGIN_DIR . '/' . $ce;
    file_put_contents($principal, file_get_contents($principal) . "\n// MANIPULADO\n");
    file_put_contents(WP_PLUGIN_DIR . '/classic-editor/puerta.php', '<?php // puerta trasera');
    wp_mkdir_p(WP_PLUGIN_DIR . '/propio');
    file_put_contents(WP_PLUGIN_DIR . '/propio/propio.php', "<?php\n/* Plugin Name: Propio\nVersion: 1.0 */");

    $r = $pedir('update', ['type' => 'plugin', 'item' => $ce, 'reinstall' => true]);
    $fresco();
    $ok(!empty($r['success']), 'reinstalar responde bien: ' . ($r['message'] ?? ''));
    $ok(!file_exists(WP_PLUGIN_DIR . '/classic-editor/puerta.php'), 'el archivo añadido desaparece');
    $ok(strpos(file_get_contents($principal), 'MANIPULADO') === false, 'el archivo manipulado vuelve al original');
    $ok(get_plugin_data($principal, false, false)['Version'] === '1.6.5', 'sigue en la misma versión (1.6.5)');
    $ok(in_array($ce, (array) get_option('active_plugins'), true), 'sigue activo');
    $ok(isset(get_site_transient('update_plugins')->response[$ce]) && version_compare(get_site_transient('update_plugins')->response[$ce]->new_version, '1.6.5', '>'),
        'la actualización pendiente de verdad se conserva');

    $r = $pedir('update', ['type' => 'plugin', 'item' => 'propio/propio.php', 'reinstall' => true]);
    $ok(empty($r['success']) && strpos($r['message'] ?? '', 'wordpress.org') !== false, 'un plugin propio no se reinstala: ' . ($r['message'] ?? ''));
    $st = $pedir('status', []);
    $lista = array_column($st['data']['plugins_list'] ?? [], 'wporg', 'file');
    $ok(($lista[$ce] ?? null) === true && ($lista['propio/propio.php'] ?? null) === false, 'status marca qué plugins son de wordpress.org');

    // 3) Desconectar a todos
    $id = wp_insert_user(['user_login' => 'prueba_sesiones', 'user_pass' => 'Vieja-123456', 'user_email' => 'prueba_sesiones@example.com', 'role' => 'editor']);
    WP_Session_Tokens::get_instance($id)->create(time() + 3600);
    $r = $pedir('sesiones', ['accion' => 'cerrar']);
    $ok(!empty($r['success']) && !WP_Session_Tokens::get_instance($id)->get_all(), 'cerrar: el usuario se queda sin sesiones');
    $fresco();
    $ok(!is_wp_error(wp_authenticate('prueba_sesiones', 'Vieja-123456')), 'cerrar: puede volver a entrar con su contraseña');

    // 4) Forzar contraseña
    WP_Session_Tokens::get_instance($id)->create(time() + 3600);
    sleep(1); // si entró y WordPress recifró la contraseña en este mismo segundo, contaría como cambiada
    $r = $pedir('sesiones', ['accion' => 'forzar_clave']);
    $fresco();
    $ok(!empty($r['success']) && !WP_Session_Tokens::get_instance($id)->get_all(), 'forzar: sesiones cerradas');
    $u = wp_authenticate('prueba_sesiones', 'Vieja-123456');
    $ok(is_wp_error($u) && $u->get_error_code() === 'webminder_clave_nueva', 'forzar: con la contraseña vieja no entra');
    $u = wp_authenticate('prueba_sesiones', 'Vieja-123456');
    $ok(is_wp_error($u) && $u->get_error_code() === 'webminder_clave_nueva', 'forzar: tampoco al segundo intento');
    $u = wp_authenticate('prueba_sesiones', 'mal');
    $ok(is_wp_error($u) && $u->get_error_code() === 'incorrect_password', 'forzar: con una contraseña mala, el error normal (no manda correo)');
    sleep(1); // cambiada >= desde, en segundos
    wp_set_password('Nueva-654321', $id);
    $ok(!is_wp_error(wp_authenticate('prueba_sesiones', 'Nueva-654321')), 'forzar: tras cambiarla entra');
    $r = $pedir('sesiones', ['accion' => 'otra']);
    $ok(empty($r['success']), 'acción desconocida rechazada');
} finally {
    if (!empty($id)) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($id);
    }
    delete_option(WebminderAgent::OPTION_CLAVE_DESDE);
    @unlink(WP_PLUGIN_DIR . '/propio/propio.php');
    @rmdir(WP_PLUGIN_DIR . '/propio');
    update_option(WebminderAgent::OPTION_CLIENT_SECRET, $secreto_orig, false);
}
echo $fallos ? "\n$fallos FALLOS\n" : "\nTodo OK\n";
