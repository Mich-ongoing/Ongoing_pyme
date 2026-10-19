<?php
/**
 * Se ejecuta cuando el usuario borra el plugin desde el panel de WordPress.
 * Elimina todos los rastros en la base de datos y en disco para no dejar
 * basura y obligar a una nueva configuración si se vuelve a instalar.
 */

// Si la llamada no viene directamente de WordPress para desinstalar, salir por seguridad.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    die;
}

// 1. Opciones a limpiar
delete_option('ongoing_webminder_client_id');
delete_option('ongoing_webminder_client_secret');
delete_option('ongoing_webminder_last_sync');
delete_option('ongoing_webminder_maintenance_mode');
delete_option('ongoing_webminder_db_version');
delete_option('ongoing_webminder_last_flush');
delete_option('ongoing_webminder_file_edit_state');
delete_option('ongoing_webminder_update_failures');

// 1b. Log de actividad local: tabla y cron
global $wpdb;
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ongoing_webminder_activity");
if (function_exists('wp_clear_scheduled_hook')) {
    wp_clear_scheduled_hook('ongoing_webminder_flush_activity');
}

// 2. Transients del auto-actualizador y de SSO (por si quedó alguno vivo)
delete_transient('ongoing_webminder_update_info');
global $wpdb;
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\_transient\_ongoing\_sso\_token\_%'
        OR option_name LIKE '\_transient\_timeout\_ongoing\_sso\_token\_%'"
);

// 3. Eliminar respaldos de actualización en disco
$backup_dir = WP_CONTENT_DIR . '/webminder-backups';
if (is_dir($backup_dir)) {
    $rrmdir = function ($dir) use (&$rrmdir) {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    };
    $rrmdir($backup_dir);
}
