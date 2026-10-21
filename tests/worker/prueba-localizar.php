<?php
/**
 * Autocomprobación de /localizar del worker (dónde aparece un dominio en la web):
 *   cp tests/worker/prueba-localizar.php <wp>/ && ddev wp eval-file prueba-localizar.php
 * Por HTTP real, como el panel. Deja el sitio como estaba.
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
$peticion = function ($payload) use ($secreto) {
    $cuerpo = json_encode($payload);
    $ts = time();
    $r = wp_remote_post(rest_url('webminder/v1/localizar'), ['timeout' => 300, 'sslverify' => false, 'body' => $cuerpo, 'headers' => [
        'Content-Type' => 'application/json', 'X-Webminder-Timestamp' => (string) $ts,
        'X-Webminder-Signature-V2' => WebminderAgent::sign('POST', '/webminder/v1/localizar', $ts, $cuerpo, $secreto),
    ]]);
    return [(int) wp_remote_retrieve_response_code($r), json_decode((string) wp_remote_retrieve_body($r), true)];
};

[$st] = $peticion(['dominio' => "x'; DROP TABLE wp_options; --"]);
$ok($st === 400, 'un dominio raro → 400');

update_option('widget_prueba_localizar', [2 => ['content' => '<script src="https://cdn-robo-prueba.example/s.js"></script>']]);
$tema = get_stylesheet_directory() . '/prueba-localizar.php';
file_put_contents($tema, "<?php\n// uno\nadd_action('wp_head', function () { echo '<script src=\"https://cdn-robo-prueba.example/t.js\"></script>'; });\n");
[$st, $d] = $peticion(['dominio' => 'cdn-robo-prueba.example']);
$donde = array_column($d['hallado'] ?? [], 'donde');
$ok($st === 200 && in_array('Base de datos · opción widget_prueba_localizar', $donde, true), 'lo encuentra en una opción de la BD');
$ok((bool) preg_grep('~^Archivo · wp-content/themes/.+/prueba-localizar\.php \(línea 3\)$~', $donde), 'y en el archivo del tema, con la línea');
$ok(($d['archivos_revisados'] ?? 0) > 1000 && isset($d['portada']['navegador']['codigo'], $d['portada']['watchWP']['wordpress']), 'revisa los archivos y la portada como navegador y como watchWP');
$ok(($d['portada']['navegador']['aparece'] ?? true) === false && $d['portada']['navegador']['wordpress'] === true, 'la portada de ahora no lo tiene (el archivo del tema no se carga) y es WordPress');

unlink($tema);
delete_option('widget_prueba_localizar');
update_option(WebminderAgent::OPTION_CLIENT_ID, $id_orig, false);
update_option(WebminderAgent::OPTION_CLIENT_SECRET, $secreto_orig, false);
echo $fallos ? "\n$fallos FALLOS\n" : "\nTodo correcto\n";
exit($fallos ? 1 : 0);
