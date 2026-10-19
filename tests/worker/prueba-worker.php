<?php
/**
 * Autocomprobación del worker dentro de un WordPress real:
 *   cp tests/worker/prueba-worker.php <wp>/ && ddev wp eval-file prueba-worker.php
 * Deja el sitio como estaba (credenciales, opciones y posts de prueba se restauran/borran).
 */

use Ongoing\WebminderWorker\WebminderAgent;

$fallos = 0;
$ok = function ($cond, $msg) use (&$fallos) {
    echo ($cond ? "  ok  " : "  FALLO ") . $msg . "\n";
    if (!$cond) {
        $fallos++;
    }
};

$id_orig = get_option(WebminderAgent::OPTION_CLIENT_ID);
$secreto_orig = get_option(WebminderAgent::OPTION_CLIENT_SECRET);
$secreto = 'sk_prueba_' . bin2hex(random_bytes(20));
update_option(WebminderAgent::OPTION_CLIENT_ID, 'webminder_test', false);
update_option(WebminderAgent::OPTION_CLIENT_SECRET, $secreto, false);

$peticion = function ($ruta, $payload = [], $opts = []) use (&$secreto) {
    $cuerpo = json_encode($payload ?: new stdClass());
    $ts = $opts['ts'] ?? time();
    $firma_ruta = $opts['firma_ruta'] ?? $ruta;
    $req = new WP_REST_Request('POST', '/webminder/v1/' . $ruta);
    $req->set_body($cuerpo);
    $req->set_header('Content-Type', 'application/json');
    $req->set_header('X-Webminder-Timestamp', (string) $ts);
    if (empty($opts['sin_firma'])) {
        $req->set_header('X-Webminder-Signature-V2', $opts['firma'] ?? WebminderAgent::sign('POST', '/webminder/v1/' . $firma_ruta, $ts, $cuerpo, $opts['secreto'] ?? $secreto));
    }
    $res = rest_do_request($req);
    return [$res->get_status(), $res->get_data(), $req];
};

echo "Firma v2\n";
[$st] = $peticion('ping-auth', [], ['sin_firma' => true]);
$ok($st === 401, 'sin firma → 401');
[$st, $d] = $peticion('ping-auth');
$ok($st === 200 && !empty($d['success']), 'firma correcta → 200');
$ts = time() - 5;
$cuerpo = '{}';
$firma = WebminderAgent::sign('POST', '/webminder/v1/ping-auth', $ts, $cuerpo, $secreto);
[$st] = $peticion('ping-auth', [], ['ts' => $ts, 'firma' => $firma]);
[$st2] = $peticion('ping-auth', [], ['ts' => $ts, 'firma' => $firma]);
$ok($st === 200 && $st2 === 401, 'la misma firma dos veces → la 2ª se rechaza (replay)');
[$st] = $peticion('disconnect', [], ['firma_ruta' => 'status']);
$ok($st === 401 && get_option(WebminderAgent::OPTION_CLIENT_SECRET) === $secreto, 'firma de /status usada en /disconnect → 401 y no desconecta');
[$st] = $peticion('ping-auth', [], ['ts' => time() - 3600]);
$ok($st === 401, 'timestamp de hace 1 h → 401');
[$st] = $peticion('ping-auth', [], ['secreto' => 'sk_otro_' . str_repeat('a', 40)]);
$ok($st === 401, 'secreto equivocado → 401');

echo "Actualizar: validación y bloqueo\n";
[$st] = $peticion('update', ['type' => 'plugin', 'item' => '../../wp-config.php']);
$ok($st === 404, 'ruta arbitraria → 404 sin tocar nada');
[$st] = $peticion('update', ['type' => 'plugin', 'item' => 'no-existe/no-existe.php']);
$ok($st === 404, 'plugin no instalado → 404');
add_option(WebminderAgent::OPTION_UPDATE_LOCK, time(), '', 'no');
[$st] = $peticion('update', ['type' => 'plugin', 'item' => 'webminder-worker/webminder-worker.php']);
$ok($st === 409, 'con otra actualización en curso → 409');
update_option(WebminderAgent::OPTION_UPDATE_LOCK, time() - 3600);
$m = new ReflectionMethod(WebminderAgent::class, 'acquire_update_lock');
$agente = new WebminderAgent();
$ok($m->invoke($agente) === true, 'un bloqueo huérfano de hace 1 h se recupera');
delete_option(WebminderAgent::OPTION_UPDATE_LOCK);

echo "Sondas del sitio\n";
$probe = new ReflectionMethod(WebminderAgent::class, 'probe_site');
$r = $probe->invoke($agente, [home_url('/')]);
$ok(!empty($r['admin-ajax']['ok']), 'admin-ajax responde webminder-ok (' . json_encode($r['admin-ajax']) . ')');
$ok(!empty($r[home_url('/')]['ok']), 'la home responde sin error (' . json_encode($r[home_url('/')]) . ')');
$reg = (new ReflectionMethod(WebminderAgent::class, 'probe_regressions'))->invoke($agente,
    ['a' => ['ok' => true], 'b' => ['ok' => false], 'admin-ajax' => ['ok' => true]],
    ['a' => ['ok' => false, 'why' => 'HTTP 500'], 'b' => ['ok' => false, 'why' => 'HTTP 403'], 'admin-ajax' => ['ok' => true]]);
$ok($reg === ['a: HTTP 500'], 'solo cuenta como regresión lo que antes iba bien');

echo "Carpeta de copias\n";
$dir = WebminderAgent::backup_dir();
$ok((bool) preg_match('~/webminder-backups-[0-9a-f]{16}$~', $dir) && is_file($dir . '/index.php'), 'nombre aleatorio + index.php');
$ok(WebminderAgent::backup_dir() === $dir, 'el nombre es estable entre llamadas');

echo "Copia de la BD\n";
$raro = "100% seguro %s 'comillas' \\barra \x00nulo ñ €";
update_option('webminder_prueba_raro', $raro, false);
[$st, $d] = $peticion('backup-db');
$archivo = ABSPATH . ($d['file'] ?? '');
$ok($st === 200 && is_file($archivo) && $d['size'] > 1000, 'crea ' . ($d['file'] ?? '?') . ' (' . ($d['size'] ?? 0) . ' bytes)');
$sql = implode('', gzfile($archivo));
global $wpdb;
$esperado = "'" . $wpdb->remove_placeholder_escape($wpdb->_real_escape($raro)) . "'";
$ok(strpos($sql, $esperado) !== false, 'el valor con % y caracteres raros va intacto');
$ok(strpos($sql, "CREATE TABLE `{$wpdb->prefix}options`") !== false, 'incluye CREATE TABLE de options');
for ($i = 0; $i < 3; $i++) {
    sleep(1); // misma petición en el mismo segundo = misma firma = replay rechazado
    $peticion('backup-db');
}
$ok(count(glob($dir . '/db-*.sql.gz')) === 3, 'se conservan solo las 3 últimas copias');
echo "  (último archivo para la prueba de importación: $archivo)\n";
file_put_contents(ABSPATH . 'webminder-ultima-copia.txt', $archivo);
delete_option('webminder_prueba_raro');

echo "Limpieza de BD\n";
$pid = wp_insert_post(['post_title' => 'WM prueba', 'post_status' => 'draft', 'post_type' => 'post']);
update_post_meta($pid, '_wm_meta', 'principal');
$rev = wp_save_post_revision($pid) ?: _wp_put_post_revision(get_post($pid));
add_metadata('post', $rev, '_elementor_data', str_repeat('x', 1000));
[$st, $d] = $peticion('db-clean', ['items' => ['revisions']]);
$ok($st === 200 && !$wpdb->get_var($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE ID = %d", $rev)), 'borra la revisión');
$ok((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->postmeta WHERE post_id = %d", $rev)) === 0, 'y su postmeta (antes quedaba huérfana)');
$ok(get_post_meta($pid, '_wm_meta', true) === 'principal', 'la meta del post real sigue');
wp_delete_post($pid, true);
[$st] = $peticion('db-clean', ['items' => ['DROP TABLE']]);
$ok($st === 400, 'un item fuera de la lista → 400');

echo "SSO\n";
$agencia = get_user_by('email', 'soporte@ongoing.es');
$ok(!$agencia, '(no existe soporte@ongoing.es en este sitio)');
[$st, $d] = $peticion('sso');
$ok($st === 404 && ($d['code'] ?? '') === 'no_agency_account', 'sin cuenta de agencia → 404, no entra con el admin del cliente');
$admins = function_exists('cerrojo_admins_confiados') ? cerrojo_admins_confiados() : [];
$uid = wp_insert_user(['user_login' => 'wm_soporte', 'user_email' => 'soporte@ongoing.es', 'user_pass' => wp_generate_password(32), 'role' => 'subscriber']);
update_option('cerrojo_admins', array_merge($admins, [$uid]), false); // = wp cerrojo admin wm_soporte; si no, Cerrojo lo degrada
(new WP_User($uid))->set_role('administrator');
sleep(1); // misma petición que la anterior en el mismo segundo = replay
[$st, $d] = $peticion('sso');
$token = $st === 200 ? wp_parse_args(wp_parse_url($d['login_url'], PHP_URL_QUERY))['ongoing_sso'] : '';
if ($st !== 200) { echo '       respuesta: ' . json_encode($d) . "\n"; }
$ok($st === 200 && (int) get_transient('ongoing_sso_token_' . hash('sha256', $token)) === $uid, 'con cuenta de agencia → enlace de un solo uso ligado a esa cuenta');
delete_transient('ongoing_sso_token_' . hash('sha256', $token));
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($uid);
update_option('cerrojo_admins', $admins, false);

echo "Mantenimiento\n";
[$st] = $peticion('maintenance-mode', ['active' => true]);
$r = wp_remote_get(add_query_arg('wm', wp_rand(), home_url('/')), ['sslverify' => false]);
$ok(wp_remote_retrieve_response_code($r) === 503, 'activado → la home da 503');
$peticion('maintenance-mode', ['active' => false]);
$r = wp_remote_get(add_query_arg('wm', wp_rand(), home_url('/')), ['sslverify' => false]);
$ok(wp_remote_retrieve_response_code($r) === 200, 'desactivado → 200');

echo "Cerrojo\n";
[$st, $d] = $peticion('status');
$ok($st === 200 && !empty($d['data']['cerrojo']['installed']), 'status incluye Cerrojo ' . ($d['data']['cerrojo']['version'] ?? '?'));
[$st, $d] = $peticion('security-scan');
$ok($st === 200 && isset($d['findings']) && is_array($d['findings']), 'security-scan devuelve ' . count($d['findings'] ?? []) . ' hallazgos');
foreach (array_slice($d['findings'] ?? [], 0, 10) as $f) {
    echo "       · $f\n";
}

echo "Rotación y desconexión\n";
[$st] = $peticion('rotate', ['secret' => 'corto']);
$ok($st === 400, 'secreto con formato inválido → 400');
$nuevo = 'sk_' . bin2hex(random_bytes(32));
[$st] = $peticion('rotate', ['secret' => $nuevo]);
$viejo = $secreto;
$secreto = $nuevo;
[$st2] = $peticion('ping-auth');
[$st3] = $peticion('ping-auth', [], ['secreto' => $viejo]);
$ok($st === 200 && $st2 === 200 && $st3 === 401, 'rotado: el nuevo vale y el viejo no');
[$st] = $peticion('disconnect');
[$st2] = $peticion('ping-auth');
$ok($st === 200 && $st2 === 401 && !get_option(WebminderAgent::OPTION_CLIENT_SECRET), 'desconectar revoca el secreto');

// Restaurar las credenciales originales del sitio
update_option(WebminderAgent::OPTION_CLIENT_ID, $id_orig, false);
update_option(WebminderAgent::OPTION_CLIENT_SECRET, $secreto_orig, false);

echo $fallos ? "\n$fallos FALLOS\n" : "\nTodo OK\n";
