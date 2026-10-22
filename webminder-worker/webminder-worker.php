<?php
/**
 * Plugin Name: Ongoing - Mantenimiento Web
 * Plugin URI:  https://ongoing.es
 * Description: Este plugin permite la gestión eficiente y segura del servicio de mantenimiento web prestado por ONGOING DIGITAL SL a su sitio web según los servicios contratados.
 * Version:     2.9.0
 * Author:      ONGOING DIGITAL SL
 * Author URI:  https://ongoing.es
 * License:     GPL2
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Text Domain: ongoing-webminder
 * Domain Path: /languages
 * Update URI:  https://ongoing.es/ongoing-worker
 */

namespace Ongoing\WebminderWorker;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Guard anti doble-carga: si el archivo se incluyera dos veces, evitar el
// fatal "Cannot redeclare class" saliendo antes de definir nada de nuevo.
if (defined('ONGOING_WEBMINDER_LOADED')) {
    return;
}
define('ONGOING_WEBMINDER_LOADED', true);

class WebminderAgent {

    const VERSION = '2.9.0';
    const API_NAMESPACE = 'webminder/v1';
    const TEXT_DOMAIN = 'ongoing-webminder';
    const DB_CLEAN_MAX_POSTS = 500; // tope de posts borrados por request (evita timeout/WSOD)

    // ================= BRAND BLOCK =================
    // Identidad de marca de esta build. scripts/build-plugin-zip.php
    // --brand=<slug> reescribe SOLO este bloque y la cabecera del plugin
    // con los valores de webminder-worker/branding/<slug>.json. No meter
    // lógica aquí: el build verifica que fuera de este bloque ambas
    // variantes quedan byte-idénticas.
    const BRAND_NAME         = 'Ongoing';
    const BRAND_AUTHOR       = 'ONGOING DIGITAL SL';
    const BRAND_LEGAL        = 'ONGOING DIGITAL SL';
    const BRAND_URL          = 'https://ongoing.es';
    const BRAND_COLOR        = '#4441FF';
    const BRAND_COLOR_DARK   = '#3A37D9';
    const BRAND_MANIFEST_URL = 'https://ongoing.es/ongoing-worker/manifest.json';
    // Emails preferidos para el SSO 1-Click: siempre se intenta entrar
    // con la cuenta de la agencia (evita contaminar el log de auditoría
    // con el usuario admin del cliente). Orden de preferencia.
    const SSO_PREFERRED_EMAILS = ['michell@ongoing.es', 'hello@ongoing.es'];
    // ================= END BRAND BLOCK =================

    // Clave pública Ed25519 del panel (la misma que usa Injerto). El código de Cerrojo que
    // llega por /cerrojo tiene que venir firmado con su privada: el secreto del sitio está
    // en la BD y no basta para meter código en mu-plugins. ONGOING_WEBMINDER_PANEL_KEY la
    // sustituye (pruebas).
    const PANEL_PUBLIC_KEY = 'ofPJ7gfD5deA1nFt7rf6Fbd9B1XUbVfAHwrl+xENuMU=';

    // Tolerancia máxima de desfase entre el reloj del firmante y el del
    // servidor. Las firmas ahora se generan en el servidor del panel
    // (con NTP), así que la ventana puede ser amplia sin riesgo.
    const TIMESTAMP_TOLERANCE = 900; // 15 minutos

    // Antigüedad máxima de los respaldos de actualización antes de purgarlos.
    const BACKUP_MAX_AGE = 604800; // 7 días

    // Opciones en BD
    const OPTION_CLIENT_ID = 'ongoing_webminder_client_id';
    const OPTION_CLIENT_SECRET = 'ongoing_webminder_client_secret';
    const OPTION_DB_VERSION = 'ongoing_webminder_db_version';
    // Último fallo de actualización por ítem (mapa item => {requires_license,
    // message, at}). Persiste la razón por la que un plugin/tema no se
    // actualizó (p. ej. "Requiere licencia") para mostrarla en el panel
    // aunque se recargue. Se limpia al actualizarse con éxito y se poda a los
    // ítems aún pendientes en cada /updates. autoload=no (mapa pequeño).
    const OPTION_UPDATE_FAILURES = 'ongoing_webminder_update_failures';
    // URL base del panel que vinculó el sitio (viene en el token de enlace).
    // De ella salen la ingesta del log y el manifiesto de autoactualización.
    const OPTION_PANEL_URL = 'ongoing_webminder_panel_url';
    const OPTION_UPDATE_LOCK = 'ongoing_webminder_update_lock';
    const OPTION_BACKUP_DIR = 'ongoing_webminder_backup_dir';
    const OPTION_JOB_PREFIX = 'ongoing_webminder_job_';
    const OPTION_CORREO = 'ongoing_webminder_correo';
    // Quien no haya cambiado la contraseña después de esta fecha no puede entrar hasta hacerlo
    const OPTION_CLAVE_DESDE = 'ongoing_webminder_clave_desde';

    private $via_correo = null;

    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('init', [$this, 'load_textdomain']);
        // Injerto pide aprobaciones al panel por el log de actividad (crítico = se envía al momento)
        add_filter('injerto_worker_conectado', function () {
            return get_option(self::OPTION_CLIENT_ID) && get_option(self::OPTION_CLIENT_SECRET) && get_option(self::OPTION_PANEL_URL);
        });
        add_action('injerto_solicitud', function ($solicitud) {
            WebminderActivityLog::instance()->log('injerto_solicitud', [
                'severity' => 'critical', 'object_type' => 'injerto', 'object_name' => $solicitud['ambito_nombre'] ?? '', 'details' => $solicitud,
            ]);
        });
        // Lo que Cerrojo bloquea o limpia también llega al log de actividad del panel
        add_action('cerrojo_aviso', function ($asunto, $texto) {
            WebminderActivityLog::instance()->log('cerrojo_aviso', [
                'severity' => 'critical', 'object_type' => 'cerrojo', 'object_name' => $asunto, 'details' => ['texto' => mb_substr((string) $texto, 0, 4000)],
            ]);
        }, 10, 2);
        // Migraciones idempotentes. También en rest_api_init: tras un
        // update lanzado desde el panel puede que nadie visite wp-admin,
        // pero el panel sí llama a /status (y la tabla de actividad debe
        // existir antes de que lleguen eventos).
        add_action('admin_init', [$this, 'maybe_upgrade']);
        add_action('rest_api_init', [$this, 'maybe_upgrade'], 5);

        // Permitir cabeceras personalizadas en preflight CORS
        add_filter('rest_allowed_cors_headers', function($headers) {
            $headers[] = 'X-Webminder-Signature';
            $headers[] = 'X-Webminder-Timestamp';
            return $headers;
        });

        // Evitar que cachés (LiteSpeed, WP Rocket, Cloudflare) almacenen
        // las respuestas de nuestros endpoints: deben ser siempre frescas.
        add_filter('rest_post_dispatch', [$this, 'no_cache_rest_responses'], 10, 3);

        // Interceptar tráfico para mostrar página de mantenimiento
        add_action('template_redirect', [$this, 'check_maintenance_mode']);

        // Sonda del chequeo post-actualización: si llega aquí, el admin cargó todos los plugins sin fatal
        add_action('wp_ajax_nopriv_webminder_probe', [$this, 'ajax_probe']);
        add_action('wp_ajax_webminder_probe', [$this, 'ajax_probe']);

        // Último envío de correo (formularios incluidos): el panel lo enseña para saber si llegan
        add_action('phpmailer_init', function ($phpmailer) {
            $this->via_correo = $phpmailer->Mailer; // 'mail' = PHP mail() sin SMTP
        }, PHP_INT_MAX);
        add_action('wp_mail_succeeded', function () {
            $this->anotar_correo(true);
        });
        add_action('wp_mail_failed', function ($error) {
            $this->anotar_correo(false, $error->get_error_message());
        });

        // «Forzar restablecimiento de contraseña» desde el panel (después de comprobar la contraseña)
        add_filter('authenticate', [$this, 'exigir_clave_nueva'], 999);
        add_action('after_password_reset', [$this, 'anotar_clave_nueva']);
        add_action('wp_set_password', function ($password, $user_id, $antes = null) {
            // Al entrar, WordPress 6.8+ vuelve a cifrar la MISMA contraseña: eso no es cambiarla
            if (isset($antes->user_pass) && wp_check_password($password, $antes->user_pass, $user_id)) {
                return;
            }
            $this->anotar_clave_nueva($user_id);
        }, 10, 3);
        add_action('profile_update', function ($user_id, $antes) {
            $ahora = get_userdata($user_id);
            if ($ahora && isset($antes->user_pass) && $ahora->user_pass !== $antes->user_pass) {
                $this->anotar_clave_nueva($user_id);
            }
        }, 10, 2);
    }

    public function anotar_clave_nueva($user) {
        update_user_meta(is_object($user) ? $user->ID : (int) $user, 'webminder_clave_cambiada', time());
    }

    public function exigir_clave_nueva($user) {
        $desde = (int) get_option(self::OPTION_CLAVE_DESDE);
        if (!$desde || !($user instanceof \WP_User) || (int) get_user_meta($user->ID, 'webminder_clave_cambiada', true) >= $desde) {
            return $user;
        }
        // Un correo cada 15 min como mucho por usuario, aunque reintente
        $enviado = (bool) get_transient('webminder_clave_aviso_' . $user->ID);
        if (!$enviado && function_exists('retrieve_password')) {
            $enviado = retrieve_password($user->user_login) === true;
            $enviado && set_transient('webminder_clave_aviso_' . $user->ID, 1, 15 * MINUTE_IN_SECONDS);
        }
        return new \WP_Error('webminder_clave_nueva', $enviado
            ? '<strong>Por seguridad tienes que crear una contraseña nueva.</strong> Te hemos enviado a tu correo un enlace para hacerlo.'
            : '<strong>Por seguridad tienes que crear una contraseña nueva.</strong> Usa «¿Has olvidado tu contraseña?» para recibir el enlace.');
    }

    private function anotar_correo($ok, $error = '') {
        $c = (array) get_option(self::OPTION_CORREO, []);
        $c[$ok ? 'ultimo_ok' : 'ultimo_error'] = time();
        if (!$ok) {
            $c['error'] = mb_substr((string) $error, 0, 500);
        }
        if ($this->via_correo) {
            $c['via'] = $this->via_correo;
        }
        update_option(self::OPTION_CORREO, $c, false);
    }

    public function ajax_probe() {
        nocache_headers();
        echo 'webminder-ok';
        exit;
    }

    /**
     * Carga las traducciones del plugin (carpeta /languages).
     */
    public function load_textdomain() {
        load_plugin_textdomain(self::TEXT_DOMAIN, false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    /**
     * Añade cabeceras anti-caché a las respuestas de los endpoints del
     * agente, para que ningún plugin de caché las sirva obsoletas.
     */
    public function no_cache_rest_responses($response, $server, $request) {
        // Este filtro se ejecuta en TODAS las respuestas REST del sitio
        // (WooCommerce, otros plugins, etc.), así que debe ser a prueba de
        // balas: solo actúa sobre nuestras rutas y nunca llama métodos que
        // el objeto recibido podría no tener.
        if (is_object($request) && method_exists($request, 'get_route')
            && is_object($response) && method_exists($response, 'header')
            && strpos((string) $request->get_route(), '/' . self::API_NAMESPACE) === 0) {
            $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->header('Pragma', 'no-cache');
        }
        return $response;
    }

    /**
     * Se ejecuta al activar el plugin. Genera las llaves criptográficas.
     */
    public function activate() {
        // autoload 'no': estas opciones solo se necesitan en peticiones
        // REST y en la página de admin; no deben cargarse en cada
        // visita del sitio del cliente.
        if (!get_option(self::OPTION_CLIENT_ID)) {
            add_option(self::OPTION_CLIENT_ID, bin2hex(random_bytes(16)), '', 'no');
        }
        if (!get_option(self::OPTION_CLIENT_SECRET)) {
            add_option(self::OPTION_CLIENT_SECRET, bin2hex(random_bytes(32)), '', 'no');
        }
        $this->run_migrations();
    }

    /**
     * Ejecuta migraciones una sola vez por versión (al cargar el admin).
     */
    public function maybe_upgrade() {
        if (get_option(self::OPTION_DB_VERSION) === self::VERSION) {
            return;
        }
        $this->run_migrations();
    }

    /**
     * Tareas de migración idempotentes.
     */
    private function run_migrations() {
        // Sitios instalados antes de la v1.6 tienen las credenciales con
        // autoload activo; forzar que NO se autocarguen (no se necesitan en
        // el frontend). wp_set_option_autoload existe desde WP 6.4.
        if (function_exists('wp_set_option_autoload')) {
            wp_set_option_autoload(self::OPTION_CLIENT_ID, false);
            wp_set_option_autoload(self::OPTION_CLIENT_SECRET, false);
        }

        // v1.7: tabla local del log de actividad (Feature B)
        try {
            WebminderActivityLog::create_table();
        } catch (\Throwable $e) {
            // La creación se reintentará en el siguiente admin_init/rest_api_init
            return;
        }

        update_option(self::OPTION_DB_VERSION, self::VERSION, false);
    }

    /**
     * Interfaz en el wp-admin para mostrar la "Clave de Conexión".
     */
    public function add_admin_menu() {
        add_management_page(
            self::BRAND_NAME . ' ' . __('Mantenimiento', self::TEXT_DOMAIN),
            self::BRAND_NAME . ' ' . __('Mantenimiento', self::TEXT_DOMAIN),
            'manage_options',
            'ongoing-webminder',
            [$this, 'render_admin_page']
        );
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Procesar envíos de formulario POST
        $message = '';
        $message_type = 'updated';

        if (isset($_POST['ongoing_action']) && check_admin_referer('ongoing_webminder_credentials_nonce')) {
            if ($_POST['ongoing_action'] === 'save_credentials') {
                $pasted_token = isset($_POST['quick_token']) ? trim($_POST['quick_token']) : '';
                $client_id = isset($_POST['client_id']) ? sanitize_text_field(trim($_POST['client_id'])) : '';
                $client_secret = isset($_POST['client_secret']) ? sanitize_text_field(trim($_POST['client_secret'])) : '';

                if (!empty($pasted_token)) {
                    $decoded = json_decode(base64_decode($pasted_token), true);
                    if (is_array($decoded)) {
                        if (!empty($decoded['id'])) {
                            $client_id = sanitize_text_field($decoded['id']);
                        }
                        if (!empty($decoded['secret'])) {
                            $client_secret = sanitize_text_field($decoded['secret']);
                        }
                        if (!empty($decoded['panel'])) {
                            $panel_url = self::valid_panel_url($decoded['panel']);
                            if ($panel_url) {
                                update_option(self::OPTION_PANEL_URL, $panel_url, false);
                            }
                        }
                    }
                }

                if (!empty($client_id) && !empty($client_secret)) {
                    update_option(self::OPTION_CLIENT_ID, $client_id, false);
                    update_option(self::OPTION_CLIENT_SECRET, $client_secret, false);
                    update_option('ongoing_webminder_last_sync', time(), false);
                    $message = __('Credenciales guardadas y sitio vinculado exitosamente.', self::TEXT_DOMAIN);
                } else {
                    $message = __('Por favor introduce un Client ID y Client Secret válidos o pega el Token de Enlace Rápido.', self::TEXT_DOMAIN);
                    $message_type = 'error';
                }
            } elseif ($_POST['ongoing_action'] === 'disconnect') {
                self::revoke_credentials();
                $message = __('Sitio desconectado del panel.', self::TEXT_DOMAIN);
            }
        }

        $client_id = get_option(self::OPTION_CLIENT_ID);
        $client_secret = get_option(self::OPTION_CLIENT_SECRET);
        $last_sync = get_option('ongoing_webminder_last_sync');
        
        $connection_data = json_encode([
            'id' => $client_id,
            'secret' => $client_secret,
            'url' => get_site_url()
        ]);
        
        $connection_token = base64_encode($connection_data);
        $is_connected = $last_sync ? true : false;
        ?>
        <div class="wrap ongoing-admin-wrap" style="max-width: 800px; margin-top: 20px;">
            
            <?php if (!empty($message)): ?>
                <div class="notice notice-<?php echo esc_attr($message_type); ?> is-dismissible" style="margin-bottom: 20px;">
                    <p><strong><?php echo esc_html($message); ?></strong></p>
                </div>
            <?php endif; ?>

            <!-- Header Oscuro -->
            <div style="background-color: #0f172a; color: white; border-radius: 8px 8px 0 0; padding: 20px 25px; display: flex; justify-content: space-between; align-items: center;">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <div style="background-color: <?php echo esc_attr(self::BRAND_COLOR); ?>; width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/></svg>
                    </div>
                    <div>
                        <h2 style="margin: 0; color: white; font-size: 18px; font-weight: 600;"><?php echo esc_html(self::BRAND_NAME); ?> <span style="color: <?php echo esc_attr(self::BRAND_COLOR); ?>;"><?php esc_html_e('Mantenimiento Web', self::TEXT_DOMAIN); ?></span></h2>
                        <p style="margin: 3px 0 0 0; color: #94a3b8; font-size: 13px;"><?php esc_html_e('Plataforma de gestión', self::TEXT_DOMAIN); ?></p>
                    </div>
                </div>

                <?php if ($is_connected): ?>
                    <div style="border: 1px solid #10b981; color: #10b981; padding: 6px 14px; border-radius: 50px; font-size: 13px; font-weight: 500; display: flex; align-items: center; gap: 6px; background-color: rgba(16, 185, 129, 0.1);">
                        <span style="font-size: 14px; font-weight: bold;">✓</span> <?php esc_html_e('Conectado exitosamente', self::TEXT_DOMAIN); ?>
                    </div>
                <?php else: ?>
                    <div style="border: 1px solid #eab308; color: #eab308; padding: 6px 14px; border-radius: 50px; font-size: 13px; font-weight: 500; display: flex; align-items: center; gap: 6px; background-color: rgba(234, 179, 8, 0.1);">
                        ⚠️ <?php esc_html_e('Pendiente de vinculación', self::TEXT_DOMAIN); ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Body Blanco -->
            <div style="background: white; border-radius: 0 0 8px 8px; padding: 30px 25px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);">
                
                <?php if ($is_connected): ?>
                    <div style="display: flex; align-items: flex-start; gap: 20px; margin-bottom: 25px;">
                        <div style="background-color: #ecfdf5; color: #059669; padding: 12px; border-radius: 50%;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        </div>
                        <div>
                            <h3 style="margin: 0 0 10px 0; font-size: 18px; color: #0f172a;"><?php esc_html_e('Conexión Segura Establecida', self::TEXT_DOMAIN); ?></h3>
                            <p style="margin: 0 0 15px 0; font-size: 14px; color: #475569; line-height: 1.6;">
                                <?php
                                printf(
                                    esc_html__('Este sitio web está recibiendo los servicios de mantenimiento y soporte web contratados por parte de %s.', self::TEXT_DOMAIN),
                                    '<strong>' . esc_html(self::BRAND_LEGAL) . '</strong>'
                                );
                                ?>
                            </p>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Formulario de Configuración de Claves -->
                <form method="POST" action="" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin-bottom: 25px;">
                    <?php wp_nonce_field('ongoing_webminder_credentials_nonce'); ?>
                    <input type="hidden" name="ongoing_action" value="save_credentials" />

                    <h4 style="margin: 0 0 15px 0; font-size: 15px; color: #0f172a; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                        🔑 <?php esc_html_e('Vincular con Webminder Panel', self::TEXT_DOMAIN); ?>
                    </h4>

                    <!-- Opción A: Token Rápido -->
                    <div style="margin-bottom: 20px; background: #eff6ff; border: 1px solid #bfdbfe; padding: 15px; border-radius: 6px;">
                        <label style="display: block; font-weight: 600; font-size: 13px; color: #1e40af; margin-bottom: 5px;">
                            ⚡ <?php esc_html_e('Opción 1: Pegar Token de Enlace Rápido', self::TEXT_DOMAIN); ?>
                        </label>
                        <input type="text" id="ongoing_quick_token" name="quick_token" placeholder="<?php esc_attr_e('Pega el Token de Enlace Rápido generado en el Panel...', self::TEXT_DOMAIN); ?>" style="width: 100%; font-family: monospace; font-size: 12px; padding: 8px 12px; border: 1px solid #93c5fd; border-radius: 4px; background: white;" />
                        <p style="margin: 5px 0 0 0; font-size: 11px; color: #3b82f6;">
                            <?php esc_html_e('Al pegar el token aquí, se completarán automáticamente el Client ID y Client Secret.', self::TEXT_DOMAIN); ?>
                        </p>
                    </div>

                    <!-- Opción B: Claves Individuales -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                        <div>
                            <label style="display: block; font-weight: 500; font-size: 12px; color: #475569; margin-bottom: 4px;">
                                CLIENT ID (ID de Cliente)
                            </label>
                            <input type="text" id="ongoing_client_id" name="client_id" value="<?php echo esc_attr($client_id); ?>" style="width: 100%; font-family: monospace; font-size: 13px; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 4px; background: white;" placeholder="webminder_1_..." />
                        </div>
                        <div>
                            <label style="display: block; font-weight: 500; font-size: 12px; color: #475569; margin-bottom: 4px;">
                                CLIENT SECRET (Clave Secreta HMAC)
                            </label>
                            <input type="text" id="ongoing_client_secret" name="client_secret" value="<?php echo esc_attr($client_secret); ?>" style="width: 100%; font-family: monospace; font-size: 13px; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 4px; background: white;" placeholder="sk_..." />
                        </div>
                    </div>

                    <button type="submit" class="button button-primary" style="background-color: <?php echo esc_attr(self::BRAND_COLOR); ?>; border-color: <?php echo esc_attr(self::BRAND_COLOR_DARK); ?>; color: white; padding: 6px 20px; font-weight: 600; border-radius: 6px; cursor: pointer;">
                        ✓ <?php esc_html_e('Guardar y Vincular Sitio', self::TEXT_DOMAIN); ?>
                    </button>
                </form>

                <?php if ($is_connected): ?>
                    <form method="POST" action="" style="display: flex; justify-content: flex-end;">
                        <?php wp_nonce_field('ongoing_webminder_credentials_nonce'); ?>
                        <input type="hidden" name="ongoing_action" value="disconnect" />
                        <button type="submit" class="button" style="color: #ef4444; border-color: #fca5a5; background: #fff5f5; font-size: 12px; cursor: pointer;" onclick="return confirm('¿Desconectar el sitio del panel central?');">
                            🔓 <?php esc_html_e('Desconectar Sitio', self::TEXT_DOMAIN); ?>
                        </button>
                    </form>
                <?php endif; ?>

            </div>
        </div>

        <script>
        document.getElementById('ongoing_quick_token').addEventListener('input', function(e) {
            var val = e.target.value.trim();
            if (!val) return;
            try {
                var json = atob(val);
                var obj = JSON.parse(json);
                if (obj.id) document.getElementById('ongoing_client_id').value = obj.id;
                if (obj.secret) document.getElementById('ongoing_client_secret').value = obj.secret;
            } catch (err) {
                // Ignore parse errors while typing
            }
        });
        </script>
        <?php
    }

    /**
     * Registro de endpoints en la REST API.
     */
    public function register_routes() {
        register_rest_route(self::API_NAMESPACE, '/status', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_status'],
            'permission_callback' => [$this, 'verify_signature']
        ]);
        
        register_rest_route(self::API_NAMESPACE, '/sso', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_sso_request'],
            'permission_callback' => [$this, 'verify_signature']
        ]);

        register_rest_route(self::API_NAMESPACE, '/updates', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_get_updates'],
            'permission_callback' => [$this, 'verify_signature']
        ]);

        register_rest_route(self::API_NAMESPACE, '/plugins', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_get_plugins'],
            'permission_callback' => [$this, 'verify_signature']
        ]);

        register_rest_route(self::API_NAMESPACE, '/update', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_update_item'],
            'permission_callback' => [$this, 'verify_signature']
        ]);

        // Ping firmado: el panel comprueba que un secreto funciona (rotación).
        register_rest_route(self::API_NAMESPACE, '/ping-auth', [
            'methods'             => 'POST',
            'callback'            => function () { return rest_ensure_response(['success' => true, 'version' => self::VERSION]); },
            'permission_callback' => [$this, 'verify_signature']
        ]);

        // Resultado de una operación lanzada en segundo plano (ver in_background)
        register_rest_route(self::API_NAMESPACE, '/job', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_job'],
            'permission_callback' => [$this, 'verify_signature']
        ]);

        register_rest_route(self::API_NAMESPACE, '/rollback', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_rollback'],
            'permission_callback' => [$this, 'verify_signature']
        ]);

        register_rest_route(self::API_NAMESPACE, '/backup-db', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_backup_db'],
            'permission_callback' => [$this, 'verify_signature']
        ]);

        register_rest_route(self::API_NAMESPACE, '/security-scan', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_security_scan'],
            'permission_callback' => [$this, 'verify_signature']
        ]);

        register_rest_route(self::API_NAMESPACE, '/rotate', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_rotate'],
            'permission_callback' => [$this, 'verify_signature']
        ]);

        register_rest_route(self::API_NAMESPACE, '/disconnect', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_disconnect'],
            'permission_callback' => [$this, 'verify_signature']
        ]);

        register_rest_route(self::API_NAMESPACE, '/maintenance-mode', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_maintenance_mode'],
            'permission_callback' => [$this, 'verify_signature']
        ]);

        register_rest_route(self::API_NAMESPACE, '/fix-security', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_fix_security'],
            'permission_callback' => [$this, 'verify_signature']
        ]);

        register_rest_route(self::API_NAMESPACE, '/db-status', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_db_status'],
            'permission_callback' => [$this, 'verify_signature']
        ]);

        register_rest_route(self::API_NAMESPACE, '/db-clean', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_db_clean'],
            'permission_callback' => [$this, 'verify_signature']
        ]);

        register_rest_route(self::API_NAMESPACE, '/site-health', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_site_health'],
            'permission_callback' => [$this, 'verify_signature']
        ]);

        register_rest_route(self::API_NAMESPACE, '/injerto', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_injerto'],
            'permission_callback' => [$this, 'verify_signature']
        ]);

        register_rest_route(self::API_NAMESPACE, '/cerrojo', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_cerrojo'],
            'permission_callback' => [$this, 'verify_signature']
        ]);

        register_rest_route(self::API_NAMESPACE, '/localizar', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_localizar'],
            'permission_callback' => [$this, 'verify_signature']
        ]);

        register_rest_route(self::API_NAMESPACE, '/sesiones', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_sesiones'],
            'permission_callback' => [$this, 'verify_signature']
        ]);

        register_rest_route(self::API_NAMESPACE, '/probar-correo', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_probar_correo'],
            'permission_callback' => [$this, 'verify_signature']
        ]);

        register_rest_route(self::API_NAMESPACE, '/ping', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handle_ping'],
            'permission_callback' => '__return_true'
        ]);
    }

    /**
     * Rompe la conexión eliminando el timestamp de last_sync.
     */
    public function handle_disconnect(\WP_REST_Request $request) {
        self::revoke_credentials();
        return rest_ensure_response([
            'success' => true,
            'message' => 'El agente ha sido desconectado correctamente del panel central.'
        ]);
    }

    /**
     * Desconectar = el secreto deja de valer. Antes solo se borraba la marca de
     * "conectado" y quien tuviera el secreto seguía pudiendo firmar peticiones.
     */
    public static function revoke_credentials() {
        delete_option('ongoing_webminder_last_sync');
        delete_option(self::OPTION_CLIENT_ID);
        delete_option(self::OPTION_CLIENT_SECRET);
        delete_option(self::OPTION_PANEL_URL);
    }

    /**
     * Copia de la BD (tablas con el prefijo del sitio) antes de un lote de
     * actualizaciones. Se guarda en la carpeta oculta de copias; se conservan
     * las 3 últimas. Restaurar: wp db import <archivo> (a mano, a conciencia:
     * pisa pedidos/formularios recibidos desde la copia).
     */
    public function handle_backup_db(\WP_REST_Request $request) {
        if ($bg = $this->in_background($request, __FUNCTION__)) {
            return $bg;
        }
        global $wpdb;
        if (!function_exists('gzopen')) {
            return new \WP_Error('no_zlib', 'PHP sin zlib: no se puede comprimir la copia.', ['status' => 500]);
        }
        @set_time_limit(0);
        $dir = self::backup_dir();
        $file = $dir . '/db-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sql.gz';
        $gz = gzopen($file, 'wb6');
        if (!$gz) {
            return new \WP_Error('write_error', 'No se pudo crear el archivo de copia.', ['status' => 500]);
        }
        try {
            gzwrite($gz, "-- Webminder " . self::VERSION . " " . gmdate('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
            $tables = $this->prefix_tables();
            foreach ($tables as $n => $table) {
                $this->job_progress(sprintf('Copiando %s (tabla %d de %d)', $table, $n + 1, count($tables)), true);
                $q = '`' . str_replace('`', '``', $table) . '`';
                $create = $wpdb->get_row("SHOW CREATE TABLE $q", ARRAY_N);
                if (empty($create[1])) {
                    continue; // vistas u objetos raros: fuera
                }
                gzwrite($gz, "\nDROP TABLE IF EXISTS $q;\n" . $create[1] . ";\n");
                // Por lotes y por clave primaria: con LIMIT offset cada lote relee las filas
                // anteriores y una tabla de logs grande tardaba decenas de minutos
                $pk = $wpdb->get_col("SHOW KEYS FROM $q WHERE Key_name = 'PRIMARY'", 4);
                $pk = count($pk) === 1 ? '`' . str_replace('`', '``', $pk[0]) . '`' : null;
                $last = null;
                $done = 0;
                for ($offset = 0; ; $offset += 1000) {
                    if ($pk) {
                        $where = $last === null ? '' : $wpdb->prepare(" WHERE $pk > %s", $last);
                        $rows = $wpdb->get_results("SELECT * FROM $q$where ORDER BY $pk LIMIT 1000", ARRAY_A);
                    } else {
                        $rows = $wpdb->get_results("SELECT * FROM $q LIMIT $offset, 1000", ARRAY_A);
                    }
                    if ($wpdb->last_error) {
                        throw new \RuntimeException("$table: " . $wpdb->last_error);
                    }
                    if (empty($rows)) {
                        break;
                    }
                    $values = [];
                    foreach ($rows as $row) {
                        $values[] = '(' . implode(',', array_map(function ($v) use ($wpdb) {
                            // _real_escape cambia los % por un marcador de prepare(): deshacerlo o la copia sale corrupta
                            return $v === null ? 'NULL' : "'" . $wpdb->remove_placeholder_escape($wpdb->_real_escape($v)) . "'";
                        }, array_values($row))) . ')';
                    }
                    if ($pk) {
                        $last = end($rows)[trim($pk, '`')];
                    }
                    gzwrite($gz, "INSERT INTO $q VALUES\n" . implode(",\n", $values) . ";\n");
                    $done += count($rows);
                    $this->job_progress(sprintf('Copiando %s (%s filas)', $table, number_format_i18n($done)));
                }
            }
            gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
            gzclose($gz);
        } catch (\Throwable $e) {
            @gzclose($gz);
            @unlink($file);
            return new \WP_Error('dump_error', 'Copia de BD fallida: ' . $e->getMessage(), ['status' => 500]);
        }

        $dumps = glob($dir . '/db-*.sql.gz') ?: [];
        rsort($dumps); // nombre con fecha: orden lexicográfico = cronológico
        foreach (array_slice($dumps, 3) as $old) {
            @unlink($old);
        }

        return rest_ensure_response([
            'success' => true,
            'file'    => str_replace(ABSPATH, '', $file),
            'size'    => filesize($file),
            'message' => 'Copia de la base de datos creada.',
        ]);
    }

    /**
     * Si el panel manda un 'job', la operación sigue en segundo plano tras
     * responder: Cloudflare y otros proxys cortan a los 100 s (HTTP 524) y una
     * copia de BD o una actualización con sus comprobaciones puede tardar más.
     * El panel recoge el resultado en /job. Sin 'job' (panel < 2.1): síncrono.
     * @return \WP_REST_Response|null
     */
    /** @var string|null Job en curso en este proceso (para job_progress) */
    private $current_job = null;
    private $last_beat = 0;

    /**
     * Latido y texto de progreso del job en curso. El panel lo muestra y, si el
     * latido se para 5 min, da el proceso por muerto en vez de esperar al límite.
     */
    private function job_progress($text, $force = false) {
        if (!$this->current_job || (!$force && time() - $this->last_beat < 5)) {
            return;
        }
        $this->last_beat = time();
        $name = self::OPTION_JOB_PREFIX . $this->current_job;
        $state = get_option($name);
        if (is_array($state) && $state['status'] === 'running') {
            update_option($name, ['progress' => $text, 'beat' => time()] + $state, false);
        }
    }

    private function in_background(\WP_REST_Request $request, $method) {
        $params = (array) $request->get_json_params();
        $job = (string) ($params['job'] ?? '');
        if (!preg_match('/^[A-Za-z0-9]{16,64}$/', $job)) {
            return null;
        }
        $this->prune_jobs();
        if (!add_option(self::OPTION_JOB_PREFIX . $job, ['status' => 'running', 'started' => time(), 'beat' => time()], '', 'no')) {
            return new \WP_Error('job_exists', 'Ese trabajo ya existe.', ['status' => 409]);
        }
        unset($params['job']);
        $sync = clone $request;
        $sync->set_body(wp_json_encode($params));

        // Tarde en shutdown: después de que WordPress haya enviado la respuesta
        add_action('shutdown', function () use ($job, $method, $sync) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            ignore_user_abort(true);
            @set_time_limit(0);
            $name = self::OPTION_JOB_PREFIX . $job;
            // Un fatal a mitad no pasa por el catch: dejar constancia para el panel
            register_shutdown_function(function () use ($name) {
                $state = get_option($name);
                if (is_array($state) && $state['status'] === 'running') {
                    $err = error_get_last();
                    update_option($name, ['status' => 'done', 'started' => $state['started'], 'result' => [
                        'success' => false,
                        'error'   => 'El proceso en el sitio se cortó' . ($err ? ': ' . $err['message'] : '.'),
                    ]], false);
                }
            });
            $this->current_job = $job;
            try {
                $r = $this->$method($sync);
                $result = is_wp_error($r)
                    ? ['success' => false, 'error' => $r->get_error_message(), 'code' => $r->get_error_code()]
                    : rest_ensure_response($r)->get_data();
            } catch (\Throwable $e) {
                $result = ['success' => false, 'error' => 'Excepción: ' . $e->getMessage()];
            }
            $state = get_option($name);
            update_option($name, ['status' => 'done', 'started' => $state['started'] ?? time(), 'result' => $result], false);
        }, PHP_INT_MAX);

        return rest_ensure_response(['success' => true, 'status' => 'running', 'job' => $job]);
    }

    public function handle_job(\WP_REST_Request $request) {
        $job = (string) ($request->get_json_params()['job'] ?? '');
        $state = preg_match('/^[A-Za-z0-9]{16,64}$/', $job) ? get_option(self::OPTION_JOB_PREFIX . $job) : false;
        if (!is_array($state)) {
            return rest_ensure_response(['success' => true, 'status' => 'unknown']);
        }
        // 'now': el panel compara el latido con el reloj del sitio, no con el suyo
        return rest_ensure_response(['success' => true, 'now' => time()] + $state);
    }

    /** Fuera los resultados de más de un día: el panel los recoge en minutos. */
    private function prune_jobs() {
        global $wpdb;
        $names = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like(self::OPTION_JOB_PREFIX) . '%'
        ));
        foreach ($names as $name) {
            $state = get_option($name);
            if (!is_array($state) || ($state['started'] ?? 0) < time() - DAY_IN_SECONDS) {
                delete_option($name);
            }
        }
    }

    /**
     * Vuelta atrás pedida desde el panel (p. ej. tras un cambio visual): restaura
     * la copia que /update conservó con keep_backup. Las copias duran 7 días.
     */
    public function handle_rollback(\WP_REST_Request $request) {
        if ($bg = $this->in_background($request, __FUNCTION__)) {
            return $bg;
        }
        $params = (array) $request->get_json_params();
        $type = (string) ($params['type'] ?? '');
        $item = (string) ($params['item'] ?? '');
        $backup = (string) ($params['backup'] ?? '');
        if (!in_array($type, ['plugin', 'theme'], true) || !$this->item_installed($type, $item)
            || !preg_match('/^([A-Za-z0-9._-]+)-backup-\d+$/', $backup, $m)) {
            return new \WP_Error('invalid_params', 'Parámetros inválidos.', ['status' => 400]);
        }
        $slug = $m[1];
        $backup_path = self::backup_dir() . '/' . $backup;
        if (!file_exists($backup_path)) {
            return new \WP_Error('no_backup', 'La copia de la versión anterior ya no existe (se guardan 7 días).', ['status' => 404]);
        }
        $single_file = is_file($backup_path);
        $current_slug = $type === 'theme' ? $item : (dirname($item) === '.' ? basename($item, '.php') : dirname($item));
        // La copia tiene que ser de ESTE elemento (un hello.php actualizado vive en hello-dolly/hello.php)
        if ($current_slug !== $slug && !($single_file && basename($item, '.php') === $slug)) {
            return new \WP_Error('backup_mismatch', 'Esa copia no corresponde a este elemento.', ['status' => 400]);
        }
        if (!$this->acquire_update_lock()) {
            return new \WP_Error('busy', 'Hay una actualización en curso en este sitio. Reintenta en unos minutos.', ['status' => 409]);
        }
        ob_start();
        try {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            global $wp_filesystem;
            WP_Filesystem();
            if (empty($wp_filesystem) || !is_object($wp_filesystem)) {
                require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
                require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
                $wp_filesystem = new \WP_Filesystem_Direct(null);
            }
            $this->job_progress('Restaurando la versión anterior', true);
            $was_active = $type === 'plugin' && is_plugin_active($item);
            $network = $type === 'plugin' && is_multisite() && is_plugin_active_for_network($item);

            if ($single_file) {
                if (dirname($item) !== '.') {
                    $wp_filesystem->delete(WP_PLUGIN_DIR . '/' . dirname($item), true);
                }
                $target = WP_PLUGIN_DIR . '/' . $slug . '.php';
                $restored = @copy($backup_path, $target);
                wp_opcache_invalidate($target, true);
                $old_item = $slug . '.php';
            } else {
                $target = ($type === 'theme' ? get_theme_root() : WP_PLUGIN_DIR) . '/' . $slug;
                $wp_filesystem->delete($target, true);
                $restored = copy_dir($backup_path, $target) === true;
                $old_item = $item;
            }
            if ($was_active) {
                $this->keep_plugin_active($old_item, $network, $item);
            }
            $this->purge_all_caches();
            ob_end_clean();

            if (!$restored) {
                return new \WP_Error('restore_failed', 'La restauración falló. Copia conservada en ' . str_replace(ABSPATH, '', $backup_path) . ' para recuperarla a mano.', ['status' => 500]);
            }
            $wp_filesystem->delete($backup_path, true);
            return rest_ensure_response(['success' => true, 'item' => $old_item, 'message' => 'Restaurada la versión anterior.']);
        } catch (\Throwable $e) {
            @ob_end_clean();
            return new \WP_Error('restore_failed', 'Excepción al restaurar: ' . $e->getMessage(), ['status' => 500]);
        } finally {
            delete_option(self::OPTION_UPDATE_LOCK);
        }
    }

    /**
     * Estado de Cerrojo (mu-plugin de seguridad propio) si está instalado.
     * @return array<string,mixed>
     */
    /**
     * Decisión del panel sobre una solicitud de Injerto. Injerto comprueba la firma
     * Ed25519 con su clave pública: esta petición (firmada con el secreto del sitio,
     * que está en la BD) no basta por sí sola para publicar nada.
     */
    public function handle_injerto(\WP_REST_Request $request) {
        if (!function_exists('injerto_aplicar_decision')) {
            return new \WP_Error('no_injerto', 'Injerto no está activo en este sitio.', ['status' => 404]);
        }
        return rest_ensure_response(injerto_aplicar_decision((array) $request->get_json_params()));
    }

    private function cerrojo_info() {
        if (!function_exists('cerrojo_vigilancia')) {
            return ['installed' => false];
        }
        $file = (new \ReflectionFunction('cerrojo_vigilancia'))->getFileName();
        $head = get_file_data($file, ['Version' => 'Version']);
        // Si se subió una versión por Plugins y no llegó a mu-plugins, la que carga sigue siendo la vieja
        $copia = WP_PLUGIN_DIR . '/cerrojo/cerrojo.php';
        $copia = is_file($copia) && wp_normalize_path($copia) !== wp_normalize_path($file) ? get_file_data($copia, ['Version' => 'Version'])['Version'] : null;
        return [
            'installed'  => true,
            'version'    => $head['Version'],
            'copia_plugins' => $copia !== $head['Version'] ? $copia : null,
            'mu_plugin'  => strpos(wp_normalize_path($file), wp_normalize_path(WPMU_PLUGIN_DIR)) === 0,
            'pending'    => (int) get_option('cerrojo_pendientes'),
        ] + (function_exists('cerrojo_estado') ? cerrojo_estado() : []); // permiso de instalar y plugins apartados (1.5+)
    }

    /**
     * Cerrojo desde el panel: instalarlo o actualizarlo (código firmado por el panel),
     * abrir o cerrar la instalación de plugins (permiso firmado que comprueba el propio
     * Cerrojo) y limpiar lo que encuentre.
     */
    public function handle_cerrojo(\WP_REST_Request $request) {
        $p = (array) $request->get_json_params();
        $accion = (string) ($p['accion'] ?? '');
        if ($accion === 'instalar') {
            return $this->cerrojo_instalar((string) ($p['codigo'] ?? ''), (string) ($p['firma'] ?? ''));
        }
        if (!function_exists('cerrojo_permiso')) {
            return new \WP_Error('no_cerrojo', 'Este sitio no tiene Cerrojo 1.5 o posterior: actualízalo primero.', ['status' => 404]);
        }
        if ($accion === 'permiso') {
            return cerrojo_permiso((int) ($p['hasta'] ?? 0), (string) ($p['firma'] ?? ''))
                ? rest_ensure_response(['success' => true, 'cerrojo' => $this->cerrojo_info()])
                : new \WP_Error('firma', 'Cerrojo no aceptó la firma del permiso.', ['status' => 403]);
        }
        if ($accion === 'limpiar') {
            if ($bg = $this->in_background($request, __FUNCTION__)) {
                return $bg;
            }
            @set_time_limit(0);
            if (function_exists('wp_raise_memory_limit')) {
                wp_raise_memory_limit('admin');
            }
            $hechos = cerrojo_escanear(true);
            // Lo heurístico no se limpia a ciegas: queda para revisarlo en Herramientas → Cerrojo.
            $quedan = array_merge(array_filter($hechos, fn($l) => strpos($l, 'NO SE PUDO') !== false), array_map('cerrojo_texto', cerrojo_vigilancia()));
            update_option('cerrojo_pendientes', count($quedan), false);
            return rest_ensure_response(['success' => true, 'hechos' => array_values($hechos), 'findings' => array_values($quedan), 'cerrojo' => $this->cerrojo_info()]);
        }
        if (in_array($accion, ['usuarios', 'desbloquear', 'admin'], true)) {
            return $this->cerrojo_usuarios($accion, $p);
        }
        return new \WP_Error('accion', 'Acción de Cerrojo desconocida.', ['status' => 400]);
    }

    /**
     * Usuarios de Cerrojo desde el panel (lo que hacían «wp cerrojo confiar» y «wp cerrojo admin»).
     * Desbloquear solo quita el bloqueo por intentos; dar administrador exige firma del panel,
     * porque el secreto de este sitio está en su BD y no debe bastar para crear un admin.
     */
    private function cerrojo_usuarios($accion, $p) {
        if (!function_exists('cerrojo_usuarios')) {
            return new \WP_Error('no_cerrojo', 'Este sitio no tiene Cerrojo 1.6 o posterior: actualízalo primero.', ['status' => 404]);
        }
        if ($accion !== 'usuarios') {
            $login = (string) ($p['login'] ?? '');
            $u = get_user_by('login', $login);
            if (!$u) {
                return new \WP_Error('usuario', 'No existe el usuario ' . $login . '.', ['status' => 404]);
            }
            if ($accion === 'desbloquear') {
                cerrojo_desbloquear($u);
            } else {
                $hasta = (int) ($p['hasta'] ?? 0);
                if ($hasta < time() || $hasta > time() + 900 || !cerrojo_firma_panel(cerrojo_mensaje_admin($login, $hasta), (string) ($p['firma'] ?? ''))) {
                    return new \WP_Error('firma', 'Autorizar un administrador necesita la firma del panel (o ha caducado).', ['status' => 403]);
                }
                cerrojo_autorizar_admin($u);
            }
        }
        return rest_ensure_response(['success' => true, 'usuarios' => cerrojo_usuarios()]);
    }

    /**
     * Escribe Cerrojo en mu-plugins si viene firmado por el panel y no es más antiguo que
     * el instalado (una firma vieja no sirve para bajar de versión). Si la web deja de
     * responder con él, vuelve al anterior.
     */
    private function cerrojo_instalar($codigo, $firma) {
        $clave = base64_decode(defined('ONGOING_WEBMINDER_PANEL_KEY') ? ONGOING_WEBMINDER_PANEL_KEY : self::PANEL_PUBLIC_KEY, true);
        $f = base64_decode($firma, true);
        if ($f === false || strlen($f) !== 64 || strlen((string) $clave) !== 32
            || !sodium_crypto_sign_verify_detached($f, 'cerrojo|codigo|' . hash('sha256', $codigo), $clave)) {
            return new \WP_Error('firma', 'El código de Cerrojo no viene firmado por el panel.', ['status' => 403]);
        }
        if (strpos($codigo, 'Plugin Name: Cerrojo') === false || !preg_match('/^\s*\*\s*Version:\s*([0-9.]+)/m', $codigo, $m)) {
            return new \WP_Error('codigo', 'Eso no es Cerrojo.', ['status' => 400]);
        }
        $info = $this->cerrojo_info();
        if (!empty($info['version']) && version_compare($m[1], $info['version'], '<')) {
            return new \WP_Error('version', "Este sitio ya tiene Cerrojo {$info['version']}; no se instala la {$m[1]}.", ['status' => 409]);
        }
        $destinos = [WPMU_PLUGIN_DIR . '/cerrojo.php'];
        // La copia de plugins/ también: al reactivarla se copiaría vieja sobre mu-plugins.
        if (is_file(WP_PLUGIN_DIR . '/cerrojo/cerrojo.php')) {
            $destinos[] = WP_PLUGIN_DIR . '/cerrojo/cerrojo.php';
        }
        wp_mkdir_p(WPMU_PLUGIN_DIR);
        $antes = $this->probe_site([home_url('/')]);
        $anteriores = [];
        foreach ($destinos as $d) {
            $anteriores[$d] = is_file($d) ? file_get_contents($d) : null;
            // Escribir aparte y renombrar: WordPress nunca carga un archivo a medio escribir
            if (file_put_contents($d . '.nuevo', $codigo) === false || !rename($d . '.nuevo', $d)) {
                @unlink($d . '.nuevo');
                $this->cerrojo_volver($anteriores);
                return new \WP_Error('escritura', 'No se pudo escribir ' . $d, ['status' => 500]);
            }
            function_exists('opcache_invalidate') && opcache_invalidate($d, true);
        }
        if ($fallos = $this->probe_regressions($antes, $this->probe_site([home_url('/')]))) {
            $this->cerrojo_volver($anteriores);
            return new \WP_Error('rompe', 'La web falla con Cerrojo ' . $m[1] . ' (' . implode(', ', $fallos) . '): se ha vuelto a la versión anterior.', ['status' => 500]);
        }
        return rest_ensure_response(['success' => true, 'version' => $m[1]]);
    }

    /**
     * Dónde aparece un dominio en la web: base de datos, archivos y la portada tal como
     * sale ahora. Para los avisos de «script externo nuevo» del panel. Solo lee.
     */
    /** Cierra la sesión de todos los usuarios y, con forzar_clave, les exige una contraseña nueva al volver. */
    public function handle_sesiones(\WP_REST_Request $request) {
        $accion = $request->get_json_params()['accion'] ?? '';
        if (!in_array($accion, ['cerrar', 'forzar_clave'], true)) {
            return new \WP_Error('accion', 'Acción no válida.', ['status' => 400]);
        }
        if ($accion === 'forzar_clave') {
            update_option(self::OPTION_CLAVE_DESDE, time(), false);
        }
        \WP_Session_Tokens::destroy_all_for_all_users();

        return rest_ensure_response([
            'success' => true,
            'message' => $accion === 'forzar_clave'
                ? 'Sesiones cerradas. Cada usuario tendrá que crear una contraseña nueva la próxima vez que entre.'
                : 'Sesiones cerradas: todos los usuarios tendrán que volver a entrar.',
        ]);
    }

    /** Manda un correo de prueba por wp_mail (el mismo camino que los formularios). */
    public function handle_probar_correo(\WP_REST_Request $request) {
        $para = sanitize_email((string) ($request->get_json_params()['para'] ?? ''));
        if (!is_email($para)) {
            return new \WP_Error('para', 'Correo no válido.', ['status' => 400]);
        }
        $error = '';
        $capturar = function ($e) use (&$error) {
            $error = $e->get_error_message();
        };
        add_action('wp_mail_failed', $capturar);
        $ok = wp_mail($para, 'Prueba de correo de ' . wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            "Correo de prueba enviado desde watchWP por " . home_url() . ".\nSi te ha llegado, los formularios de esta web pueden enviar correo.");
        remove_action('wp_mail_failed', $capturar);

        return rest_ensure_response([
            'success' => $ok,
            'error'   => $ok ? null : ($error ?: 'wp_mail() devolvió false sin dar motivo.'),
            'correo'  => get_option(self::OPTION_CORREO),
        ]);
    }

    public function handle_localizar(\WP_REST_Request $request) {
        $dominio = strtolower(trim((string) ($request->get_json_params()['dominio'] ?? '')));
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $dominio)) {
            return new \WP_Error('dominio', 'Dominio no válido.', ['status' => 400]);
        }
        if ($bg = $this->in_background($request, __FUNCTION__)) {
            return $bg;
        }
        @set_time_limit(0);
        global $wpdb;
        // Unos 300 caracteres alrededor de la primera aparición, para ver qué es
        $trozo = function ($texto) use ($dominio) {
            $i = stripos((string) $texto, $dominio);
            return wp_check_invalid_utf8(substr((string) $texto, max(0, $i - 150), strlen($dominio) + 300), true);
        };
        $like = '%' . $wpdb->esc_like($dominio) . '%';
        $hallado = [];
        foreach ($wpdb->get_results($wpdb->prepare("SELECT option_name AS r, option_value AS v FROM $wpdb->options WHERE option_value LIKE %s LIMIT 20", $like)) as $r) {
            $hallado[] = ['donde' => "Base de datos · opción {$r->r}", 'trozo' => $trozo($r->v)];
        }
        foreach ($wpdb->get_results($wpdb->prepare("SELECT ID AS r, post_type AS t, post_title AS n, post_content AS v FROM $wpdb->posts WHERE post_content LIKE %s LIMIT 20", $like)) as $r) {
            $hallado[] = ['donde' => "Base de datos · {$r->t} #{$r->r} «{$r->n}»", 'trozo' => $trozo($r->v)];
        }
        foreach ($wpdb->get_results($wpdb->prepare("SELECT post_id AS r, meta_key AS k, meta_value AS v FROM $wpdb->postmeta WHERE meta_value LIKE %s LIMIT 20", $like)) as $r) {
            $hallado[] = ['donde' => "Base de datos · {$r->k} de la entrada #{$r->r}", 'trozo' => $trozo($r->v)];
        }
        // Archivos de texto de toda la web (no copias de BD ni lo ya puesto en cuarentena)
        $archivos = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(ABSPATH, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD);
        foreach ($it as $f) {
            $ruta = $f->getPathname();
            if (!$f->isFile() || $f->getSize() > 3 * MB_IN_BYTES || !preg_match('/\.(php\d?|phtml|js|mjs|html?|json|css|txt|inc|tpl|twig|htaccess)$/i', $ruta)
                || preg_match('~/(webminder-backups-[^/]+|node_modules|[^/]*\.cuarentena[^/]*)/~', $ruta)) {
                continue;
            }
            $archivos++;
            $c = (string) @file_get_contents($ruta);
            if (stripos($c, $dominio) !== false && count($hallado) < 60) {
                $hallado[] = ['donde' => 'Archivo · ' . str_replace(ABSPATH, '', $ruta) . ' (línea ' . (substr_count(substr($c, 0, stripos($c, $dominio)), "\n") + 1) . ')', 'trozo' => $trozo($c)];
            }
        }
        // La portada ahora, como un navegador y como la vigilancia del panel
        $portada = [];
        foreach (['navegador' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36', 'watchWP' => 'watchWP uptime'] as $quien => $ua) {
            $r = wp_remote_get(add_query_arg('watchwp', time(), home_url('/')), ['timeout' => 30, 'sslverify' => false, 'user-agent' => $ua, 'redirection' => 3]);
            $html = is_wp_error($r) ? '' : (string) wp_remote_retrieve_body($r);
            $portada[$quien] = [
                'codigo'   => is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r),
                'aparece'  => stripos($html, $dominio) !== false,
                'trozo'    => stripos($html, $dominio) !== false ? $trozo($html) : '',
                'wordpress' => (bool) preg_match('#/wp-(content|includes)/#', $html),
            ];
        }
        return rest_ensure_response(['success' => true, 'dominio' => $dominio, 'portada' => $portada, 'hallado' => $hallado, 'archivos_revisados' => $archivos]);
    }

    private function cerrojo_volver($anteriores) {
        foreach ($anteriores as $d => $codigo) {
            $codigo === null ? @unlink($d) : file_put_contents($d, $codigo);
            function_exists('opcache_invalidate') && opcache_invalidate($d, true);
        }
    }

    /**
     * Revisión completa de Cerrojo lanzada desde el panel. SOLO lectura: la
     * limpieza y la cuarentena siguen siendo manuales en Herramientas → Cerrojo,
     * igual que en su revisión diaria (no tocar archivos legítimos a ciegas).
     */
    public function handle_security_scan(\WP_REST_Request $request) {
        if ($bg = $this->in_background($request, __FUNCTION__)) {
            return $bg;
        }
        if (!function_exists('cerrojo_escanear') || !function_exists('cerrojo_vigilancia')) {
            return new \WP_Error('no_cerrojo', 'Cerrojo no está instalado en este sitio.', ['status' => 404]);
        }
        @set_time_limit(0);
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }
        $findings = array_merge(cerrojo_escanear(false), array_map('cerrojo_texto', cerrojo_vigilancia()));
        update_option('cerrojo_pendientes', count($findings), false);
        return rest_ensure_response([
            'success'  => true,
            'cerrojo'  => $this->cerrojo_info(),
            'findings' => array_values(array_map('strval', $findings)),
        ]);
    }

    /**
     * Rotación del secreto pedida por el panel (firmada con el secreto actual).
     */
    public function handle_rotate(\WP_REST_Request $request) {
        $params = $request->get_json_params();
        $secret = isset($params['secret']) ? (string) $params['secret'] : '';
        if (!preg_match('/^[A-Za-z0-9_]{40,128}$/', $secret)) {
            return new \WP_Error('invalid_secret', 'Secreto nuevo con formato inválido.', ['status' => 400]);
        }
        update_option(self::OPTION_CLIENT_SECRET, $secret, false);
        return rest_ensure_response(['success' => true, 'message' => 'Secreto rotado.']);
    }

    /**
     * URL del panel admitida: https, o http solo para entornos locales (.ddev.site / localhost).
     * @return string|null
     */
    public static function valid_panel_url($url) {
        $url = esc_url_raw(trim((string) $url));
        $scheme = wp_parse_url($url, PHP_URL_SCHEME);
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            return null;
        }
        $local = $host === 'localhost' || substr($host, -10) === '.ddev.site' || strpos($host, 'ddev-') === 0;
        if ($scheme === 'https' || ($scheme === 'http' && $local)) {
            return untrailingslashit($url);
        }
        return null;
    }

    /**
     * Endpoint ligero para monitoreo de Uptime. Retorna 200 OK y la hora del servidor.
     */
    public function handle_ping() {
        return rest_ensure_response([
            'status' => 'ok',
            'time' => time(),
            'version' => self::VERSION
        ]);
    }

    /**
     * Devuelve el estado de la base de datos (elementos basura)
     */
    public function handle_db_status(\WP_REST_Request $request) {
        global $wpdb;

        // Revisiones
        $revisions_count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'revision'");
        
        // Spam Comments
        $spam_count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->comments WHERE comment_approved = 'spam'");
        
        // Trashed Comments
        $trashed_comments = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->comments WHERE comment_approved = 'trash'");

        // Transients: solo los EXPIRADOS (datos muertos), excluyendo el detector
        // de updates. No se cuentan los transients vivos (cachés en uso).
        $transients_count = count($this->expired_transients());

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'revisions' => (int) $revisions_count,
                'spam_comments' => (int) $spam_count,
                'trashed_comments' => (int) $trashed_comments,
                'transients' => (int) $transients_count,
                'auto_drafts' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_status = 'auto-draft'"),
                'trashed_posts' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_status = 'trash'"),
                'optimize_tables' => count($this->prefix_tables()),
            ]
        ]);
    }

    /**
     * Transients que NUNCA deben borrarse en la limpieza de BD: los que usan
     * WordPress y el worker para DETECTAR actualizaciones. Si se borran, el
     * panel deja de ver los plugins/temas pendientes hasta el siguiente
     * re-chequeo (hasta 12 h). Incluye sus claves de timeout.
     */
    private function preserved_transient_names() {
        return [
            '_site_transient_update_plugins', '_site_transient_timeout_update_plugins',
            '_site_transient_update_themes',  '_site_transient_timeout_update_themes',
            '_site_transient_update_core',    '_site_transient_timeout_update_core',
            '_transient_' . WebminderUpdater::CACHE_KEY,
            '_transient_timeout_' . WebminderUpdater::CACHE_KEY,
        ];
    }

    /**
     * Transients EXPIRADOS (su timeout ya pasó), EXCLUYENDO los preservados del
     * detector de updates. Devuelve pares [nombre_valor, nombre_timeout].
     *
     * Antes la limpieza borraba TODOS los transients no preservados, incluidos
     * los VIVOS (p. ej. cachés de licencia de plugins premium → podía forzar una
     * revalidación). Ahora solo se tocan los caducados (datos muertos), que es lo
     * que hacen WP-Optimize y similares.
     */
    private function expired_transients() {
        global $wpdb;
        $preserve = array_flip($this->preserved_transient_names());
        $now = time();
        $out = [];
        $scopes = [
            // value_prefix => [timeout_prefix, es_site_transient]
            '_transient_'      => ['_transient_timeout_', false],
            '_site_transient_' => ['_site_transient_timeout_', true],
        ];
        foreach ($scopes as $vprefix => $cfg) {
            list($tprefix, $is_site) = $cfg;
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
                $wpdb->esc_like($tprefix) . '%',
                $now
            ));
            foreach ((array) $rows as $tname) {
                $key = substr($tname, strlen($tprefix));
                $vname = $vprefix . $key;
                if (isset($preserve[$tname]) || isset($preserve[$vname])) {
                    continue;
                }
                // 'key' (sin prefijo) para borrar con delete_(site_)transient, que
                // SÍ invalida la caché de opciones (a diferencia de un DELETE crudo).
                $out[] = ['key' => $key, 'site' => $is_site];
            }
        }
        return $out;
    }

    /**
     * Nombres de tabla que pertenecen a ESTA instalación (prefijo propio).
     * Se usa como allowlist para OPTIMIZE TABLE: nunca se toca una tabla
     * fuera del prefijo. A prueba de fallos: excepción => [].
     *
     * @return string[]
     */
    private function prefix_tables() {
        global $wpdb;
        try {
            $like = $wpdb->esc_like($wpdb->prefix) . '%';
            $names = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));
            $prefix = $wpdb->prefix;
            $out = [];
            foreach ((array) $names as $name) {
                // Doble verificación: el nombre DEBE empezar por el prefijo.
                if (is_string($name) && $name !== '' && strpos($name, $prefix) === 0) {
                    $out[] = $name;
                }
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Limpia la base de datos según los parámetros seleccionados
     */
    public function handle_db_clean(\WP_REST_Request $request) {
        global $wpdb;
        $params = $request->get_json_params();
        $items_to_clean = isset($params['items']) ? (array) $params['items'] : [];

        // Whitelist explícita: cualquier valor fuera de esta lista se descarta.
        $allowed_items = ['revisions', 'spam_comments', 'trashed_comments', 'transients', 'auto_drafts', 'trashed_posts', 'optimize_tables'];
        $items_to_clean = array_values(array_intersect($items_to_clean, $allowed_items));

        if (empty($items_to_clean)) {
            return rest_ensure_response(new \WP_Error('invalid_items', 'No se indicaron elementos válidos para limpiar.', ['status' => 400]));
        }

        $cleaned = [];

        if (in_array('revisions', $items_to_clean)) {
            $wpdb->query("DELETE FROM $wpdb->posts WHERE post_type = 'revision'");
            // Sin esto queda la postmeta de cada revisión (con Elementor, _elementor_data: lo que más ocupa)
            $wpdb->query("DELETE pm FROM $wpdb->postmeta pm LEFT JOIN $wpdb->posts p ON p.ID = pm.post_id WHERE p.ID IS NULL");
            $wpdb->query("DELETE tr FROM $wpdb->term_relationships tr LEFT JOIN $wpdb->posts p ON p.ID = tr.object_id LEFT JOIN $wpdb->links l ON l.link_id = tr.object_id WHERE p.ID IS NULL AND l.link_id IS NULL");
            $cleaned[] = 'revisions';
        }

        if (in_array('spam_comments', $items_to_clean)) {
            $wpdb->query("DELETE FROM $wpdb->comments WHERE comment_approved = 'spam'");
            $cleaned[] = 'spam_comments';
        }

        if (in_array('trashed_comments', $items_to_clean)) {
            $wpdb->query("DELETE FROM $wpdb->comments WHERE comment_approved = 'trash'");
            $cleaned[] = 'trashed_comments';
        }

        if (array_intersect(['spam_comments', 'trashed_comments'], $items_to_clean)) {
            $wpdb->query("DELETE cm FROM $wpdb->commentmeta cm LEFT JOIN $wpdb->comments c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL");
        }

        if (in_array('transients', $items_to_clean)) {
            // Solo transients EXPIRADOS (datos muertos), preservando los vivos
            // (p. ej. licencias de plugins premium) y el detector de updates.
            // delete_(site_)transient invalida también la caché de opciones.
            foreach ($this->expired_transients() as $t) {
                if ($t['site']) {
                    delete_site_transient($t['key']);
                } else {
                    delete_transient($t['key']);
                }
            }
            $cleaned[] = 'transients';
        }

        $counters = [];

        if (in_array('auto_drafts', $items_to_clean, true)) {
            $ids = get_posts(['post_type' => 'any', 'post_status' => 'auto-draft', 'numberposts' => self::DB_CLEAN_MAX_POSTS, 'fields' => 'ids', 'suppress_filters' => true]);
            $n = 0;
            foreach ((array) $ids as $id) {
                try { if (wp_delete_post((int) $id, true)) { $n++; } } catch (\Throwable $e) {}
            }
            $counters['deleted_auto_drafts'] = $n;
            $counters['auto_drafts_remaining'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_status = 'auto-draft'");
            $cleaned[] = 'auto_drafts';
        }

        if (in_array('trashed_posts', $items_to_clean, true)) {
            $ids = get_posts(['post_type' => 'any', 'post_status' => 'trash', 'numberposts' => self::DB_CLEAN_MAX_POSTS, 'fields' => 'ids', 'suppress_filters' => true]);
            $n = 0;
            foreach ((array) $ids as $id) {
                try { if (wp_delete_post((int) $id, true)) { $n++; } } catch (\Throwable $e) {}
            }
            $counters['deleted_trashed_posts'] = $n;
            $counters['trashed_posts_remaining'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_status = 'trash'");
            $cleaned[] = 'trashed_posts';
        }

        if (in_array('optimize_tables', $items_to_clean, true)) {
            $n = 0;
            foreach ($this->prefix_tables() as $table) {
                // Nombre escapado a mano entre backticks (NO $wpdb->prepare('%s'),
                // que mete comillas y rompe la sintaxis — el bug de Manage).
                $safe = '`' . str_replace('`', '``', $table) . '`';
                try {
                    if ($wpdb->query("OPTIMIZE TABLE $safe") !== false) { $n++; }
                } catch (\Throwable $e) {}
            }
            $counters['optimized_tables'] = $n;
            $cleaned[] = 'optimize_tables';
        }

        return rest_ensure_response(array_merge([
            'success' => true,
            'message' => 'Limpieza de base de datos completada.',
            'cleaned' => $cleaned,
        ], $counters));
    }

    /**
     * Activa o desactiva el modo mantenimiento
     */
    public function handle_maintenance_mode(\WP_REST_Request $request) {
        $params = $request->get_json_params();
        // Comparación estricta: solo el booleano true activa el modo
        $active = isset($params['active']) && true === $params['active'];
        
 
        update_option('ongoing_webminder_maintenance_mode', $active);
        // Sin purga, la caché de página seguiría sirviendo la web (o la pantalla de mantenimiento)
        $this->purge_all_caches();
        
        return rest_ensure_response([
            'success' => true,
            'message' => $active ? 'Modo mantenimiento activado' : 'Modo mantenimiento desactivado'
        ]);
    }

    /**
     * Intenta corregir problemas de seguridad comunes
     */
    public function handle_fix_security(\WP_REST_Request $request) {
        $params = $request->get_json_params();
        $issue = isset($params['issue']) ? $params['issue'] : '';

        if ($issue === 'file_edit') {
            $config_path = ABSPATH . 'wp-config.php';
            if (!file_exists($config_path)) {
                $config_path = dirname(ABSPATH) . '/wp-config.php';
            }

            if (!file_exists($config_path) || !is_writable($config_path)) {
                return rest_ensure_response(new \WP_Error('no_permission', 'El archivo wp-config.php no existe o no tiene permisos de escritura.', ['status' => 403]));
            }

            // Backup previo: si la escritura falla o corrompe el archivo,
            // se restaura. wp-config.php roto = sitio caído.
            $backup_path = $config_path . '.ongoing-backup';
            if (!@copy($config_path, $backup_path)) {
                return rest_ensure_response(new \WP_Error('backup_failed', 'No se pudo crear el respaldo de wp-config.php. Operación cancelada.', ['status' => 500]));
            }

            $config_content = file_get_contents($config_path);

            // Guard: lectura fiable. Si falla o el contenido no parece un
            // wp-config real (sin DB_NAME), abortar SIN escribir — escribir
            // basura aquí vaciaría wp-config.php y tumbaría el sitio (WSOD).
            if ($config_content === false || strpos($config_content, 'DB_NAME') === false) {
                @unlink($backup_path);
                return rest_ensure_response(new \WP_Error('read_error', 'No se pudo leer wp-config.php de forma fiable. Operación cancelada.', ['status' => 500]));
            }

            // Remove existing definitions of DISALLOW_FILE_EDIT if any
            $stripped = preg_replace("/define\(\s*['\"]DISALLOW_FILE_EDIT['\"]\s*,\s*(true|false)\s*\);/i", "", $config_content);

            // preg_replace devuelve null ante un error de PCRE (p. ej. backtrack):
            // en ese caso NO tocamos el archivo (sigue intacto en disco).
            if ($stripped === null) {
                @unlink($backup_path);
                return rest_ensure_response(new \WP_Error('regex_error', 'No se pudo procesar wp-config.php. Operación cancelada (archivo intacto).', ['status' => 500]));
            }
            $config_content = $stripped;

            $insert_string = "\n/** Añadido por Webminder */\ndefine( 'DISALLOW_FILE_EDIT', true );\n";

            if (strpos($config_content, "That's all, stop editing!") !== false) {
                $config_content = str_replace("/* That's all, stop editing!", $insert_string . "/* That's all, stop editing!", $config_content);
            } else {
                $config_content .= $insert_string;
            }

            // Guard final: el contenido a escribir debe seguir conteniendo DB_NAME
            // (sanity check anti-corrupción). Si no, restaurar y abortar.
            if (strpos($config_content, 'DB_NAME') === false) {
                @copy($backup_path, $config_path);
                @unlink($backup_path);
                return rest_ensure_response(new \WP_Error('sanity_error', 'El contenido resultante de wp-config.php no superó la validación. Se restauró el respaldo.', ['status' => 500]));
            }

            $result = file_put_contents($config_path, $config_content);

            if ($result !== false) {
                @unlink($backup_path);

                // Atribuir el cambio al panel: se registra aquí (petición HMAC
                // → "Sistema Mantenimiento") y se sincroniza el snapshot para
                // que el diff diferido de check_file_edit_state() no lo vuelva a
                // registrar luego, fuera de contexto, como "Desconocido".
                try {
                    $prev = get_option(WebminderActivityLog::OPTION_FILE_EDIT_STATE);
                    if ($prev !== 'disabled') {
                        update_option(WebminderActivityLog::OPTION_FILE_EDIT_STATE, 'disabled', false);
                        WebminderActivityLog::instance()->log('file_edit_disabled', [
                            'severity'    => 'info',
                            'object_type' => 'setting',
                            'object_name' => 'DISALLOW_FILE_EDIT',
                            'details'     => ['old' => $prev === false ? 'enabled' : $prev, 'new' => 'disabled'],
                        ]);
                    }
                } catch (\Throwable $e) {
                    // El log jamás debe afectar a la respuesta de la acción
                }

                return rest_ensure_response([
                    'success' => true,
                    'message' => 'Seguridad corregida: Se ha deshabilitado la edición de archivos.'
                ]);
            } else {
                // Restaurar el original ante un fallo de escritura
                @copy($backup_path, $config_path);
                @unlink($backup_path);
                return rest_ensure_response(new \WP_Error('write_error', 'Error al intentar escribir en wp-config.php. Se restauró el respaldo.', ['status' => 500]));
            }
        }

        return rest_ensure_response(new \WP_Error('invalid_issue', 'Problema no reconocido.', ['status' => 400]));
    }

    /**
     * Muestra la pantalla de mantenimiento si está activo
     */
    public function check_maintenance_mode() {
        if (get_option('ongoing_webminder_maintenance_mode') && !current_user_can('manage_options')) {
            status_header(503);
            header('Retry-After: 3600');
            // Impedir que cualquier caché guarde la pantalla de mantenimiento
            // (de lo contrario seguiría mostrándose tras desactivarlo).
            nocache_headers();

            $title = esc_html__('En mantenimiento', self::TEXT_DOMAIN);
            $heading = esc_html__('En mantenimiento, volvemos en breve.', self::TEXT_DOMAIN);
            $subtext = esc_html__('Estamos haciendo algunos ajustes.', self::TEXT_DOMAIN);

            echo '<!DOCTYPE html>
            <html lang="' . esc_attr(get_bloginfo('language')) . '">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>' . $title . '</title>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; background-color: #f8fafc; color: #334155; text-align: center; }
                    .container { max-width: 600px; padding: 40px; background: white; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06); }
                    h1 { font-size: 24px; margin-bottom: 16px; color: #0f172a; }
                    p { font-size: 16px; line-height: 1.5; color: #64748b; }
                    svg { width: 64px; height: 64px; margin-bottom: 24px; color: #64748b; }
                </style>
            </head>
            <body>
                <div class="container">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                      <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    <h1>' . $heading . '</h1>
                    <p>' . $subtext . '</p>
                </div>
            </body>
            </html>';
            exit;
        }
    }

    /**
     * Re-chequea las actualizaciones contra wordpress.org y reinyecta la del
     * propio worker, PRESERVANDO los plugins premium (cuyo updater solo engancha
     * en wp-admin y se perdería en un rebuild en contexto REST).
     *
     * Problema que resuelve: en hosts con WP-Cron roto (frecuente en SiteGround
     * y similares), WP no re-chequea actualizaciones y su transient queda viejo,
     * así que el panel muestra "al día" cuando en realidad hay updates (que sí
     * se ven al entrar al wp-admin, porque visitarlo dispara el chequeo). Esto
     * lo hace de forma OPORTUNISTA (aprovechando el tráfico de /status del panel)
     * y THROTTLED, salvo cuando el panel lo fuerza ("Refrescar Lista").
     *
     * A prueba de fallos: cualquier excepción se traga (el estado se devuelve
     * igual). Salida HTTP a wordpress.org: estos hosts ya la permiten (el propio
     * wp-admin detecta updates), por eso el chequeo funciona aunque el cron no.
     */
    private function maybe_refresh_updates($force = false) {
        try {
            if (!$force) {
                // Throttle: como mucho 1 re-chequeo/hora (la card del dashboard
                // pega a /status en batch muy seguido; no chequear en cada call).
                $last = (int) get_option('ongoing_webminder_last_update_check', 0);
                if (time() - $last < HOUR_IN_SECONDS) {
                    return;
                }
            }
            update_option('ongoing_webminder_last_update_check', time(), false);

            if (!function_exists('wp_update_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/update.php';
            }

            // El propio worker: invalidar su caché de manifiesto para que su
            // actualización (1.x→1.y) se detecte fresca tras el re-chequeo.
            delete_transient(WebminderUpdater::CACHE_KEY);

            // 1) Snapshot de premium (paquete NO de wordpress.org): un rebuild en
            //    contexto REST puede tirarlos (su updater solo engancha en admin).
            $before = get_site_transient('update_plugins');
            $premium = [];
            if (is_object($before) && !empty($before->response) && is_array($before->response)) {
                foreach ($before->response as $file => $data) {
                    $pkg = is_object($data) ? (isset($data->package) ? $data->package : '')
                        : (is_array($data) ? (isset($data['package']) ? $data['package'] : '') : '');
                    if (strpos((string) $pkg, '//downloads.wordpress.org/') === false) {
                        $premium[$file] = $data;
                    }
                }
            }

            // 2) Re-chequeo real contra wordpress.org (fuerza borrando el transient).
            delete_site_transient('update_plugins');
            wp_update_plugins();

            // 3) Re-merge de los premium que el rebuild hubiera tirado, y re-set
            //    (el set dispara pre_set_site_transient_update_plugins → reinyecta
            //    la actualización del propio worker).
            $after = get_site_transient('update_plugins');
            if (is_object($after)) {
                if (!isset($after->response) || !is_array($after->response)) {
                    $after->response = [];
                }
                foreach ($premium as $file => $data) {
                    if (!isset($after->response[$file])) {
                        $after->response[$file] = $data;
                    }
                }
                set_site_transient('update_plugins', $after);
            }

            // Temas: refrescar también (premium de temas es raro; sin merge).
            wp_update_themes();
        } catch (\Throwable $e) {
            // Nunca romper /status ni /updates por el re-chequeo.
        }
    }

    /**
     * Devuelve métricas de salud básicas (WP Versión, PHP, RAM, Conteo de Plugins).
     */
    public function handle_status(\WP_REST_Request $request) {
        global $wp_version;

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();
        $active_plugins = get_option('active_plugins', []);

        // Re-chequeo oportunista de actualizaciones (throttled): mantiene fresco
        // el conteo aunque el WP-Cron del sitio esté roto, sin pulsar "Refrescar
        // Lista". Preserva premium. A prueba de fallos.
        $this->maybe_refresh_updates(false);

        $update_plugins = get_site_transient('update_plugins');
        $update_themes = get_site_transient('update_themes');
        
        $updates_count = 0;
        if (isset($update_plugins->response) && is_array($update_plugins->response)) {
            $updates_count += count($update_plugins->response);
        }
        if (isset($update_themes->response) && is_array($update_themes->response)) {
            $updates_count += count($update_themes->response);
        }
        // Traducciones: en /updates se representan como UN ítem agregado
        // ("Traducciones"). Contarlas igual aquí (como 1) para que el badge de la
        // card del dashboard cuadre con la lista de "Elementos a actualizar".
        if (!function_exists('wp_get_translation_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }
        $translation_updates = array_filter(wp_get_translation_updates(), [$this, 'translation_matches_installed']);
        if (!empty($translation_updates)) {
            $updates_count += 1;
        }

        // Contar administradores
        $admin_users = get_users(['role' => 'administrator']);
        $admin_count = count($admin_users);

        // Verificar si la edición de archivos está permitida
        $file_edit_allowed = (!defined('DISALLOW_FILE_EDIT') || !DISALLOW_FILE_EDIT);

        $stats = [
            'wp_version' => $wp_version,
            'php_version' => phpversion(),
            'memory_limit' => ini_get('memory_limit'),
            'total_plugins' => count($plugins),
            'active_plugins' => count($active_plugins),
            'updates_pending' => $updates_count,
            'maintenance_mode' => get_option('ongoing_webminder_maintenance_mode') ? true : false,
            'admin_count' => $admin_count,
            'file_edit_allowed' => $file_edit_allowed,
            'themes' => $this->get_installed_themes(),
            'security_audit' => $this->get_security_audit(),
            'admin_users' => $this->get_admin_users_details(),
            'plugins_list' => $this->get_installed_plugins_details(),
            'environment' => $this->get_environment_info(),
            'site_health_summary' => ($site_health = $this->run_site_health()) ? ($site_health['summary'] ?? []) : [],
            'cerrojo' => $this->cerrojo_info(),
            'correo' => get_option(self::OPTION_CORREO) ?: null,
            'worker_version' => self::VERSION, // el panel lo usa para saber qué puede pedir
            // El panel vigila los scripts de la portada de las webs con Injerto
            'injerto' => defined('INJERTO_VERSION') ? ['version' => INJERTO_VERSION] : null,
        ];

        // Flush oportunista del log de actividad: aprovecha el tráfico
        // regular del panel para sincronizar eventos en sitios con el
        // WP-Cron roto. Acotado por intervalo y a prueba de fallos.
        try {
            WebminderActivityLog::instance()->maybe_flush();
        } catch (\Throwable $e) {
            // El estado de salud se devuelve igual aunque el flush falle
        }

        return rest_ensure_response([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Auditoría detallada de seguridad del sitio WordPress.
     */
    private function get_security_audit() {
        global $wpdb, $wp_version;

        $checks = [];
        $score = 100;

        // 1. HTTPS / SSL
        $is_ssl = is_ssl() || (isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) === 'on') || (strpos(site_url(), 'https://') === 0);
        if ($is_ssl) {
            $checks[] = [
                'id' => 'ssl',
                'label' => 'Certificado SSL / HTTPS',
                'status' => 'pass',
                'message' => 'El sitio web utiliza conexión segura HTTPS.'
            ];
        } else {
            $score -= 20;
            $checks[] = [
                'id' => 'ssl',
                'label' => 'Certificado SSL / HTTPS',
                'status' => 'danger',
                'message' => 'El sitio web no está usando HTTPS. Los datos viajan sin cifrar.'
            ];
        }

        // 2. Desactivar edición de archivos
        $disallow_file_edit = (defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT);
        if ($disallow_file_edit) {
            $checks[] = [
                'id' => 'file_edit',
                'label' => 'Edición de Archivos PHP',
                'status' => 'pass',
                'message' => 'La edición de archivos desde el wp-admin está deshabilitada.'
            ];
        } else {
            $score -= 15;
            $checks[] = [
                'id' => 'file_edit',
                'label' => 'Edición de Archivos PHP',
                'status' => 'warning',
                'message' => 'La edición de archivos PHP desde el panel wp-admin está permitida.'
            ];
        }

        // 3. Modo Depuración (WP_DEBUG)
        $wp_debug = (defined('WP_DEBUG') && WP_DEBUG);
        if (!$wp_debug) {
            $checks[] = [
                'id' => 'wp_debug',
                'label' => 'Modo Depuración (WP_DEBUG)',
                'status' => 'pass',
                'message' => 'Modo depuración desactivado en producción.'
            ];
        } else {
            $score -= 15;
            $checks[] = [
                'id' => 'wp_debug',
                'label' => 'Modo Depuración (WP_DEBUG)',
                'status' => 'warning',
                'message' => 'WP_DEBUG está activo en producción. Podría exponer información técnica sensible.'
            ];
        }

        // 4. Prefijo de Base de Datos
        $default_prefix = ($wpdb->prefix === 'wp_');
        if (!$default_prefix) {
            $checks[] = [
                'id' => 'db_prefix',
                'label' => 'Prefijo de Base de Datos',
                'status' => 'pass',
                'message' => "Prefijo personalizado ({$wpdb->prefix}) en lugar de wp_."
            ];
        } else {
            $score -= 10;
            $checks[] = [
                'id' => 'db_prefix',
                'label' => 'Prefijo de Base de Datos',
                'status' => 'warning',
                'message' => "La base de datos usa el prefijo por defecto 'wp_'."
            ];
        }

        // 5. Usuario genérico 'admin'
        $admin_user_exists = username_exists('admin') || username_exists('administrator');
        if (!$admin_user_exists) {
            $checks[] = [
                'id' => 'admin_username',
                'label' => 'Usuario Administrador Genérico',
                'status' => 'pass',
                'message' => "No existen usuarios con nombre 'admin' o 'administrator'."
            ];
        } else {
            $score -= 20;
            $checks[] = [
                'id' => 'admin_username',
                'label' => 'Usuario Administrador Genérico',
                'status' => 'danger',
                'message' => "Existe un usuario administrador con nombre 'admin' o 'administrator'."
            ];
        }

        // 6. XML-RPC
        $xmlrpc_enabled = apply_filters('xmlrpc_enabled', true);
        if (!$xmlrpc_enabled) {
            $checks[] = [
                'id' => 'xmlrpc',
                'label' => 'Interface XML-RPC',
                'status' => 'pass',
                'message' => 'XML-RPC está desactivado para prevenir ataques por fuerza bruta.'
            ];
        } else {
            $score -= 10;
            $checks[] = [
                'id' => 'xmlrpc',
                'label' => 'Interface XML-RPC',
                'status' => 'warning',
                'message' => 'XML-RPC está habilitado en el sitio.'
            ];
        }

        // 7. Actualizaciones pendientes de WP Core
        $update_core = get_site_transient('update_core');
        $has_core_update = false;
        if (isset($update_core->updates) && is_array($update_core->updates)) {
            foreach ($update_core->updates as $up) {
                if (isset($up->response) && $up->response === 'upgrade') {
                    $has_core_update = true;
                    break;
                }
            }
        }

        if (!$has_core_update) {
            $checks[] = [
                'id' => 'wp_core',
                'label' => 'Versión de WordPress Core',
                'status' => 'pass',
                'message' => "WordPress está actualizado (versión {$wp_version})."
            ];
        } else {
            $score -= 15;
            $checks[] = [
                'id' => 'wp_core',
                'label' => 'Versión de WordPress Core',
                'status' => 'danger',
                'message' => "WordPress tiene una actualización pendiente (actual: {$wp_version})."
            ];
        }

        return [
            'score' => max(0, $score),
            'ssl_enabled' => $is_ssl,
            'file_edit_disallowed' => $disallow_file_edit,
            'debug_mode' => $wp_debug,
            'default_db_prefix' => $default_prefix,
            'insecure_admin_exists' => (bool)$admin_user_exists,
            'xmlrpc_enabled' => $xmlrpc_enabled,
            'wp_core_outdated' => $has_core_update,
            'checks' => $checks
        ];
    }

    /**
     * Lista detallada de usuarios administradores.
     */
    private function get_admin_users_details() {
        $admins = [];
        try {
            $users = get_users(['role' => 'administrator']);
            foreach ($users as $user) {
                $is_insecure = in_array(strtolower($user->user_login), ['admin', 'administrator', 'root'], true);
                $admins[] = [
                    'id' => $user->ID,
                    'username' => $user->user_login,
                    'display_name' => $user->display_name ?: $user->user_login,
                    'email' => $user->user_email,
                    'registered' => $user->user_registered,
                    'is_insecure' => $is_insecure,
                ];
            }
        } catch (\Throwable $e) {
            return [];
        }
        return $admins;
    }

    /**
     * Lista de plugins instalados con estado y versión.
     */
    private function get_installed_plugins_details() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();
        $active_plugins = get_option('active_plugins', []);
        $update_plugins = get_site_transient('update_plugins');
        $update_map = (isset($update_plugins->response) && is_array($update_plugins->response)) ? $update_plugins->response : [];

        $result = [];
        foreach ($plugins as $file => $plugin) {
            $has_update = isset($update_map[$file]);
            $new_version = $has_update ? ($update_map[$file]->new_version ?? ($update_map[$file]['new_version'] ?? null)) : null;
            $result[] = [
                'file' => $file,
                'name' => $plugin['Name'] ?? $file,
                'version' => $plugin['Version'] ?? '',
                'author' => wp_strip_all_tags($plugin['Author'] ?? ''),
                'active' => in_array($file, $active_plugins, true),
                'update_available' => $has_update,
                'new_version' => $new_version,
                'wporg' => $this->wporg_slug($file, $update_plugins) !== null, // reinstalable desde wordpress.org
            ];
        }
        return $result;
    }

    /**
     * Slug en wordpress.org si wordpress.org reconoce el plugin como suyo (el mismo
     * criterio de WordPress al buscar actualizaciones: id «w.org/plugins/<slug>»).
     * Un plugin de pago o propio con el mismo nombre de carpeta no pasa.
     */
    private function wporg_slug($item, $updates = null) {
        $updates = $updates ?: get_site_transient('update_plugins');
        $entry = $updates->response[$item] ?? ($updates->no_update[$item] ?? null);
        $entry = $entry ? (object) $entry : null;
        $slug = $entry->slug ?? '';
        return ($slug !== '' && ($entry->id ?? '') === 'w.org/plugins/' . $slug) ? $slug : null;
    }

    /**
     * Información del entorno del servidor y base de datos.
     */
    private function get_environment_info() {
        global $wpdb;
        return [
            'php_version' => phpversion(),
            'db_version' => $wpdb->db_version(),
            'db_prefix' => $wpdb->prefix,
            'memory_limit' => ini_get('memory_limit'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'max_execution_time' => ini_get('max_execution_time'),
            'multisite' => is_multisite(),
            'site_url' => site_url(),
            'home_url' => home_url(),
        ];
    }

    /**
     * Inventario de temas instalados (nombre + versión, cuál está activo y si
     * tiene actualización pendiente). El transient de updates solo lista los
     * temas CON update; aquí devolvemos TODOS para que el panel los muestre.
     * A prueba de fallos: cualquier excepción => array vacío (no rompe /status).
     *
     * @return array<int,array<string,mixed>>
     */
    private function get_installed_themes() {
        $themes = [];
        try {
            $update_themes = get_site_transient('update_themes');
            $update_map = (isset($update_themes->response) && is_array($update_themes->response))
                ? $update_themes->response : [];

            $active_slug = get_stylesheet();

            foreach (wp_get_themes() as $slug => $theme) {
                if (!($theme instanceof \WP_Theme)) {
                    continue;
                }
                $has_update = isset($update_map[$slug]);
                $new_version = ($has_update && isset($update_map[$slug]['new_version']))
                    ? $update_map[$slug]['new_version'] : null;

                $parent = $theme->parent();
                $themes[] = [
                    'slug'             => (string) $slug,
                    'name'             => $theme->get('Name') ?: (string) $slug,
                    'version'          => $theme->get('Version') ?: '',
                    'active'           => ($slug === $active_slug),
                    'update_available' => $has_update,
                    'new_version'      => $new_version,
                    'is_child'         => ($parent instanceof \WP_Theme),
                    'parent_theme'     => ($parent instanceof \WP_Theme) ? ($parent->get('Name') ?: $parent->get_stylesheet()) : null,
                    'requires_php'     => $theme->get('RequiresPHP') ?: '',
                    'requires_wp'      => $theme->get('RequiresWP') ?: '',
                    'package'          => (isset($update_map[$slug]['package']) && is_string($update_map[$slug]['package'])) ? $update_map[$slug]['package'] : null,
                ];
            }
        } catch (\Throwable $e) {
            return [];
        }
        return $themes;
    }

    /**
     * URL del paquete (ZIP) de actualización de un ítem, si existe en el
     * mapa `->response` del transient de updates. Tolera objeto o array.
     * @return string|null
     */
    private function update_package($response, $key) {
        if (!isset($response[$key])) {
            return null;
        }
        $r = $response[$key];
        if (is_object($r) && isset($r->package) && is_string($r->package)) {
            return $r->package;
        }
        if (is_array($r) && isset($r['package']) && is_string($r['package'])) {
            return $r['package'];
        }
        return null;
    }

    /**
     * Inventario de plugins instalados (nombre + versión instalada, si está
     * activo, si tiene update pendiente y si requiere licencia). get_plugins()
     * lista TODOS los instalados (no solo los que tienen update), así que aquí
     * aparecen también los plugins premium cuya actualización WordPress NO
     * muestra dentro del panel de plugins. Solo lectura.
     * A prueba de fallos: cualquier excepción => array vacío (no rompe /plugins).
     *
     * @return array<int,array<string,mixed>>
     */
    private function get_installed_plugins() {
        $plugins = [];
        try {
            if (!function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $all      = get_plugins();
            $active   = (array) get_option('active_plugins', []);
            $update   = get_site_transient('update_plugins');
            $response = (isset($update->response) && is_array($update->response)) ? $update->response : [];
            $failures = $this->get_update_failures();

            foreach ($all as $file => $data) {
                $has_update  = isset($response[$file]);
                $new_version = null;
                if ($has_update) {
                    $r = $response[$file];
                    $new_version = (is_object($r) && isset($r->new_version)) ? $r->new_version
                        : ((is_array($r) && isset($r['new_version'])) ? $r['new_version'] : null);
                }
                $plugins[] = [
                    'file'             => (string) $file,
                    'name'             => (isset($data['Name']) && $data['Name'] !== '') ? $data['Name'] : (string) $file,
                    'version'          => isset($data['Version']) ? (string) $data['Version'] : '',
                    'active'           => in_array($file, $active, true),
                    'update_available' => $has_update,
                    'new_version'      => $new_version,
                    'requires_license' => !empty($failures[$file]['requires_license']),
                    'requires_php'     => isset($data['RequiresPHP']) ? (string) $data['RequiresPHP'] : '',
                    'requires_wp'      => isset($data['RequiresWP']) ? (string) $data['RequiresWP'] : '',
                    'package'          => $this->update_package($response, $file),
                ];
            }
        } catch (\Throwable $e) {
            return [];
        }
        return $plugins;
    }

    /**
     * Devuelve el inventario de plugins instalados (solo lectura).
     */
    public function handle_get_plugins(\WP_REST_Request $request) {
        return rest_ensure_response([
            'success' => true,
            'data'    => $this->get_installed_plugins(),
        ]);
    }

    /**
     * Salud del sitio nativa de WordPress. Delega en WP_Site_Health::get_tests()
     * (loopback, cron, REST, extensiones PHP, HTTPS, actualizaciones, etc.).
     * Solo lectura. A prueba de fallos: cualquier problema => resumen a 0 y
     * tests vacíos, jamás un 500. El gate adminOnly lo aplica wp-proxy.
     */
    public function handle_site_health(\WP_REST_Request $request) {
        return rest_ensure_response([
            'success' => true,
            'data'    => $this->run_site_health(),
        ]);
    }

    /**
     * @return array{summary: array<string,int>, tests: array<int,array<string,string>>}
     */
    private function run_site_health() {
        $empty = ['summary' => ['total' => 0, 'good' => 0, 'recommended' => 0, 'critical' => 0], 'tests' => []];
        try {
            if (!class_exists('WP_Site_Health')) {
                $file    = ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
                $update  = ABSPATH . 'wp-admin/includes/update.php';
                $misc    = ABSPATH . 'wp-admin/includes/misc.php';
                // Un require de un archivo ausente es un fatal NO atrapable por
                // try/catch: guardamos los tres con is_readable y degradamos.
                if (!is_readable($file) || !is_readable($update) || !is_readable($misc)) {
                    return $empty;
                }
                require_once $update;
                require_once $misc;
                require_once $file;
            }
            if (!class_exists('WP_Site_Health')) {
                return $empty;
            }
            $health = method_exists('WP_Site_Health', 'get_instance') ? \WP_Site_Health::get_instance() : new \WP_Site_Health();
            $tests  = $health->get_tests();

            $results = [];
            // Tests 'direct' (síncronos). Los 'async' se omiten: requieren
            // llamadas REST/cron que pueden colgar o no estar disponibles.
            $direct = isset($tests['direct']) && is_array($tests['direct']) ? $tests['direct'] : [];
            foreach ($direct as $test) {
                $r = $this->run_health_test($health, $test);
                if ($r !== null) {
                    $results[] = $r;
                }
            }

            $summary = ['total' => count($results), 'good' => 0, 'recommended' => 0, 'critical' => 0];
            foreach ($results as $r) {
                $s = $r['status'];
                if (isset($summary[$s])) {
                    $summary[$s]++;
                }
            }
            return ['summary' => $summary, 'tests' => $results];
        } catch (\Throwable $e) {
            return $empty;
        }
    }

    /**
     * Ejecuta un test individual de Site Health de forma defensiva.
     * @return array<string,string>|null
     */
    private function run_health_test($health, $test) {
        try {
            $callback = isset($test['test']) ? $test['test'] : null;
            $result = null;
            if (is_string($callback)) {
                $method = 'get_test_' . $callback;
                if (method_exists($health, $method)) {
                    $result = $health->$method();
                } elseif (is_callable($callback)) {
                    $result = call_user_func($callback);
                }
            } elseif (is_callable($callback)) {
                $result = call_user_func($callback);
            }
            if (!is_array($result) || !isset($result['status'])) {
                return null;
            }
            $label = isset($result['label']) ? wp_strip_all_tags((string) $result['label']) : '';
            $desc  = isset($result['description']) ? wp_strip_all_tags((string) $result['description']) : '';
            if (function_exists('mb_substr')) {
                $desc = mb_substr($desc, 0, 400);
            } else {
                $desc = substr($desc, 0, 400);
            }
            $badge = '';
            if (isset($result['badge']['label'])) {
                $badge = wp_strip_all_tags((string) $result['badge']['label']);
            }
            return [
                'test'        => isset($result['test']) ? (string) $result['test'] : (is_string($callback) ? $callback : ''),
                'label'       => $label,
                'status'      => (string) $result['status'], // good | recommended | critical
                'badge'       => $badge,
                'description' => trim($desc),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Genera un token temporal para hacer SSO 1-Click Login.
     */
    public function handle_sso_request(\WP_REST_Request $request) {
        $user = self::sso_user();
        if (!$user) {
            return new \WP_Error('no_agency_account', 'No existe una cuenta de la agencia (' . implode(', ', self::SSO_PREFERRED_EMAILS) . ') con rol administrador en este sitio. El SSO nunca entra con la cuenta del cliente.', ['status' => 404]);
        }

        $sso_token = bin2hex(random_bytes(16));
        // Se almacena el hash, no el token: si la BD se filtra (backup,
        // SQLi en otro plugin), los transients no revelan tokens usables.
        set_transient('ongoing_sso_token_' . hash('sha256', $sso_token), (int) $user->ID, 30);

        $login_url = add_query_arg([
            'ongoing_sso' => $sso_token
        ], get_site_url());

        return rest_ensure_response([
            'success' => true,
            'login_url' => $login_url
        ]);
    }

    /**
     * Cuenta de la agencia para el SSO, en orden de preferencia. Sin fallback
     * al admin del cliente: entrar con su cuenta sería suplantarle.
     * @return \WP_User|null
     */
    public static function sso_user() {
        foreach (self::SSO_PREFERRED_EMAILS as $email) {
            $user = get_user_by('email', $email);
            if ($user && in_array('administrator', (array) $user->roles, true)) {
                return $user;
            }
        }
        return null;
    }

    /**
     * Lista todos los plugins y temas que requieren actualización.
     */
    public function handle_get_updates(\WP_REST_Request $request) {
        if (!function_exists('get_plugin_updates')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        // "Refrescar Lista" del panel: fuerza un re-chequeo real completo contra
        // wordpress.org (bypass del throttle), preservando los plugins premium y
        // reinyectando la actualización del propio worker. Ver maybe_refresh_updates.
        $params = $request->get_json_params();
        if (is_array($params) && !empty($params['refresh'])) {
            $this->maybe_refresh_updates(true);
        }

        // El refresco lo pide la ronda nocturna en todos los sitios: buen momento para purgar
        // las copias caducadas aunque esa noche no haya nada que actualizar
        $backups_size = null;
        if (is_array($params) && !empty($params['refresh'])) {
            wp_version_check();
            $this->prune_old_backups(self::backup_dir());
            $backups_size = self::dir_size(self::backup_dir());
        }
        $plugin_updates = get_site_transient('update_plugins');
        $theme_updates = get_site_transient('update_themes');

        // Versiones INSTALADAS de los plugins (el transient de updates solo trae
        // la versión nueva). get_plugins() está en plugin.php (ya requerido arriba).
        $installed_plugins = function_exists('get_plugins') ? get_plugins() : [];

        $updates = [];

        // Núcleo primero: un plugin nuevo puede necesitar la versión nueva de WordPress
        if ($offer = $this->core_offer()) {
            $updates[] = [
                'type'            => 'core',
                'item'            => 'core',
                'name'            => 'WordPress',
                'version'         => $offer->current,
                'current_version' => get_bloginfo('version'),
            ];
        }

        if (isset($plugin_updates->response) && is_array($plugin_updates->response)) {
            foreach ($plugin_updates->response as $file => $plugin) {
                $current = isset($installed_plugins[$file]['Version']) ? $installed_plugins[$file]['Version'] : null;
                $updates[] = [
                    'type' => 'plugin',
                    'item' => $file,
                    'name' => isset($plugin->name) ? $plugin->name : (isset($plugin->slug) ? $plugin->slug : dirname($file)),
                    'version' => isset($plugin->new_version) ? $plugin->new_version : 'latest',
                    'current_version' => $current
                ];
            }
        }

        if (isset($theme_updates->response) && is_array($theme_updates->response)) {
            foreach ($theme_updates->response as $slug => $theme) {
                // Nombre "bonito" y versión instalada del tema (el transient solo
                // trae el slug y la versión nueva). wp_get_theme nunca lanza fatal.
                $wp_theme = wp_get_theme($slug);
                $theme_ok = ($wp_theme instanceof \WP_Theme) && $wp_theme->exists();
                $updates[] = [
                    'type' => 'theme',
                    'item' => $slug,
                    'name' => $theme_ok && $wp_theme->get('Name') ? $wp_theme->get('Name') : $slug,
                    'version' => isset($theme['new_version']) ? $theme['new_version'] : 'latest',
                    'current_version' => $theme_ok ? ($wp_theme->get('Version') ?: null) : null
                ];
            }
        }

        // Traducciones (paquetes de idioma) pendientes. WP NO las pone en
        // update_plugins/themes->response: las agrega aparte en
        // wp_get_translation_updates(). Las representamos como UN solo ítem
        // (igual que el botón nativo "Actualizar las traducciones") que se
        // instala en bloque con Language_Pack_Upgrader.
        if (!function_exists('wp_get_translation_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }
        $translations = array_filter(wp_get_translation_updates(), [$this, 'translation_matches_installed']);
        if (!empty($translations)) {
            $updates[] = [
                'type'    => 'translation',
                'item'    => 'translations',
                'name'    => 'Traducciones',
                'version' => (string) count($translations),
            ];
        }

        // Adjuntar el último fallo persistido (p. ej. "Requiere licencia") a
        // cada ítem pendiente, para que el panel lo muestre aunque se recargue.
        // De paso, podar los fallos de ítems que ya no están pendientes
        // (se actualizaron por otra vía o desaparecieron).
        $failures = $this->get_update_failures();
        if (!empty($failures)) {
            $still_pending = [];
            foreach ($updates as &$u) {
                $still_pending[$u['item']] = true;
                if (isset($failures[$u['item']])) {
                    $u['requires_license'] = !empty($failures[$u['item']]['requires_license']);
                    $u['last_error'] = isset($failures[$u['item']]['message']) ? $failures[$u['item']]['message'] : '';
                }
            }
            unset($u);

            $pruned = array_intersect_key($failures, $still_pending);
            if (count($pruned) !== count($failures)) {
                update_option(self::OPTION_UPDATE_FAILURES, $pruned, false);
            }
        }

        return rest_ensure_response([
            'success' => true,
            'data' => $updates,
            'backups_size' => $backups_size, // bytes de copias guardadas en el sitio (solo con refresh)
        ]);
    }

    /**
     * Actualización Segura (File-Level Rollback)
     */
    public function handle_update_item(\WP_REST_Request $request) {
        if ($bg = $this->in_background($request, __FUNCTION__)) {
            return $bg;
        }
        // Iniciar el buffer de salida para capturar cualquier echo o warning
        // que la clase upgrader de WordPress pueda soltar. Si esto se imprime,
        // rompe el JSON y el navegador lanza un falso error de CORS.
        ob_start();

        try {
            $params = $request->get_json_params();
            $type = $params['type'] ?? '';
            $item = $params['item'] ?? '';

            // Traducciones: se actualizan TODAS en bloque con
            // Language_Pack_Upgrader (no aplica el backup/rollback de archivos
            // de plugins/temas: un paquete de idioma fallido no tumba el sitio).
            if ($type === 'translation') {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
                if (!function_exists('wp_get_translation_updates')) {
                    require_once ABSPATH . 'wp-admin/includes/update.php';
                }
                // Fuera las traducciones de versiones que ya no están instaladas: en un
                // lote, el plugin acaba de actualizarse y la lista (del transient) es
                // de la versión vieja; ese paquete falla o deja una traducción desfasada.
                $translations = array_values(array_filter(wp_get_translation_updates(), [$this, 'translation_matches_installed']));
                if (empty($translations)) {
                    ob_end_clean();
                    return rest_ensure_response(['success' => true, 'message' => 'No hay traducciones pendientes.']);
                }
                WP_Filesystem();
                $skin = new \Automatic_Upgrader_Skin();
                $upgrader = new \Language_Pack_Upgrader($skin);
                // clear_update_cache=false: igual que en plugins/temas, el rebuild en REST tira los premium
                $result = $upgrader->bulk_upgrade($translations, ['clear_update_cache' => false]);

                // Resultado por paquete: en éxito un ARRAY (el de install_package),
                // no true; WP_Error/false/null en fallo. Contar solo === true
                // daba siempre "fallo" aunque se instalaran todas.
                // Las traducciones de plugins/temas PREMIUM (p. ej. Elementor
                // Pro) se descargan del servidor del proveedor y, sin licencia
                // activa, fallan con el MISMO tipo de error que el update del
                // plugin. Recogemos los mensajes y lo detectamos para marcarlo
                // como "Requiere licencia" en vez de un fallo genérico.
                $total = count($translations);
                $ok_count = 0;
                $msgs = [];
                if (is_array($result)) {
                    foreach ($result as $r) {
                        if ($r !== false && $r !== null && !is_wp_error($r)) {
                            $ok_count++;
                        } elseif (is_wp_error($r)) {
                            $msgs[] = $r->get_error_message();
                        }
                    }
                } elseif (is_wp_error($result)) {
                    $msgs[] = $result->get_error_message();
                }
                if (method_exists($skin, 'get_upgrade_messages')) {
                    foreach ((array) $skin->get_upgrade_messages() as $m) {
                        $msgs[] = html_entity_decode(wp_strip_all_tags($m), ENT_QUOTES, 'UTF-8');
                    }
                }
                ob_end_clean();

                if (($total - $ok_count) <= 0) {
                    $this->clear_update_failure('translations');
                    return rest_ensure_response(['success' => true, 'message' => 'Traducciones actualizadas correctamente.']);
                }

                $combined = implode(' | ', array_filter(array_unique($msgs)));
                $requires_license = $this->is_license_message($combined);
                if ($requires_license) {
                    $message = sprintf(
                        'Algunas traducciones no se pudieron actualizar: requieren un plugin/tema con licencia activa (p. ej. Elementor Pro). Actualizadas %d de %d.',
                        $ok_count, $total
                    );
                } else {
                    $message = ($combined !== '')
                        ? ('No se pudieron actualizar todas las traducciones. Detalle: ' . $combined)
                        : sprintf('No se pudieron actualizar todas las traducciones (%d de %d).', $ok_count, $total);
                }
                $this->record_update_failure('translations', $requires_license, $message);
                return rest_ensure_response([
                    'success'          => false,
                    'message'          => $message,
                    'requires_license' => $requires_license,
                ]);
            }

            // Núcleo: Core_Upgrader, como las actualizaciones automáticas de WordPress. No hay
            // vuelta atrás de archivos (WordPress entero y la BD ya migrada): si la web falla, se avisa.
            if ($type === 'core') {
                $offer = $this->core_offer();
                if (!$offer) {
                    ob_end_clean();
                    return rest_ensure_response(['success' => true, 'message' => 'WordPress ya está en la última versión.']);
                }
                if (!$this->acquire_update_lock()) {
                    ob_end_clean();
                    return new \WP_Error('busy', 'Ya hay otra actualización en curso en este sitio. Reintenta en unos minutos.', ['status' => 409]);
                }
                $lock_held = true;
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

                $check_urls = $this->check_urls(isset($params['check_urls']) ? $params['check_urls'] : []);
                $this->job_progress('Comprobando la web antes de actualizar', true);
                $baseline = $this->probe_site($check_urls);
                $this->job_progress('Descargando e instalando WordPress ' . $offer->current, true);
                WP_Filesystem();
                $skin = new \Automatic_Upgrader_Skin();
                $upgrader = new \Core_Upgrader($skin);
                // En éxito devuelve la versión nueva (string); WP_Error/false/null en fallo
                $result = $upgrader->upgrade($offer);

                if (!is_string($result)) {
                    $upgrader->maintenance_mode(false); // un fallo a medias puede dejar el .maintenance
                    $message = is_wp_error($result) ? $result->get_error_message() : '';
                    $skin_messages = $skin->get_upgrade_messages();
                    if ($message === '' && $skin_messages) {
                        $message = html_entity_decode(wp_strip_all_tags(end($skin_messages)), ENT_QUOTES, 'UTF-8');
                    }
                    $message = 'No se pudo actualizar WordPress: ' . ($message !== '' ? $message : 'el actualizador no confirmó el éxito.');
                    $this->record_update_failure('core', false, $message);
                    ob_end_clean();
                    return rest_ensure_response(['success' => false, 'message' => $message]);
                }

                // Sin reconstruir el transient aquí: wp_version_check aún vería la versión vieja cargada
                delete_site_transient('update_core');
                $this->clear_update_failure('core');
                $this->job_progress('Comprobando la web después de actualizar', true);
                $regressions = $this->probe_regressions($baseline, $this->probe_site($check_urls));
                $this->purge_all_caches();
                ob_end_clean();

                if (!empty($regressions)) {
                    return rest_ensure_response([
                        'success'     => false,
                        'rolled_back' => false,
                        'regressions' => $regressions,
                        'message'     => 'La web falla tras actualizar WordPress a ' . $result . ' (' . implode('; ', $regressions) . '). El núcleo no se revierte solo: revísala ya.',
                    ]);
                }
                return rest_ensure_response([
                    'success'  => true,
                    'message'  => 'WordPress actualizado a ' . $result . '.',
                    'verified' => count(array_filter($baseline, function ($p) { return $p['ok']; })) > 0,
                    'item'     => 'core',
                ]);
            }

            if (!in_array($type, ['plugin', 'theme']) || empty($item)) {
                ob_end_clean();
                return new \WP_Error('invalid_params', 'Parámetros inválidos.', ['status' => 400]);
            }
            if (!$this->item_installed($type, $item)) {
                ob_end_clean();
                return new \WP_Error('unknown_item', 'Ese plugin o tema no está instalado en el sitio.', ['status' => 404]);
            }
            // Reinstalar: la MISMA versión desde wordpress.org, por si el código del plugin se ha manipulado
            $reinstall = !empty($params['reinstall']);
            $wporg_slug = $type === 'plugin' ? $this->wporg_slug($item) : null;
            if ($reinstall && !$wporg_slug) {
                ob_end_clean();
                return rest_ensure_response(['success' => false, 'message' => 'No es un plugin gratuito de wordpress.org: no se puede reinstalar desde allí.']);
            }
            if (!$this->acquire_update_lock()) {
                ob_end_clean();
                return new \WP_Error('busy', 'Ya hay otra actualización en curso en este sitio. Reintenta en unos minutos.', ['status' => 409]);
            }
            $lock_held = true;

            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
            
            if (!class_exists('Plugin_Upgrader')) {
                require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            }

            // Usar Skin silencioso
            $upgrader_skin = new \Automatic_Upgrader_Skin();
            
            $backup_dir = self::backup_dir();

            // Purgar respaldos viejos (de procesos previos interrumpidos)
            // para que el directorio no crezca sin control.
            $this->prune_old_backups($backup_dir);

            // Plugin de un solo archivo (hello.php): se copia el archivo, no una carpeta
            $single_file = ($type === 'plugin' && dirname($item) === '.');
            $item_slug = ($type === 'plugin') ? ($single_file ? basename($item, '.php') : dirname($item)) : $item;
            $source_dir = $single_file ? WP_PLUGIN_DIR . '/' . $item
                : (($type === 'plugin') ? WP_PLUGIN_DIR . '/' . $item_slug : get_theme_root() . '/' . $item_slug);
            $backup_path = $backup_dir . '/' . $item_slug . '-backup-' . time();

            // Inicializar WP_Filesystem de forma segura
            global $wp_filesystem;
            if (!function_exists('WP_Filesystem')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            WP_Filesystem();
            
            // Si el host restringe el FS, forzamos el modo directo
            if (empty($wp_filesystem) || !is_object($wp_filesystem)) {
                require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
                require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
                $wp_filesystem = new \WP_Filesystem_Direct(null);
            }

            // === true: copy_dir devuelve WP_Error (que es "truthy") si falla a medias;
            // tomarlo por éxito haría que el rollback restaurase una copia incompleta.
            $this->job_progress('Guardando copia de los archivos actuales', true);
            $backup_success = false;
            if ($single_file && is_file($source_dir)) {
                $backup_success = @copy($source_dir, $backup_path);
            } elseif (is_dir($source_dir)) {
                $backup_success = copy_dir($source_dir, $backup_path) === true;
            }

            // Baseline ANTES del update con la versión que funcionaba: solo se
            // juzgan después las sondas que antes daban bien (un 403 por
            // geobloqueo o un loopback bloqueado por el host no son regresiones).
            $check_urls = $this->check_urls(isset($params['check_urls']) ? $params['check_urls'] : []);
            $this->job_progress('Comprobando la web antes de actualizar', true);
            $baseline = $this->probe_site($check_urls);
            $this->job_progress('Descargando e instalando la versión nueva', true);

            // Defensa del LOTE ("Actualizar Todo Seguro"): el upgrader nativo lee
            // el paquete del site transient (update_plugins/themes) y, si el ítem
            // no está ahí, lo da por "up to date" y NO actualiza. Ese transient es
            // COMPARTIDO y algo puede haberlo vaciado entre ítems del lote (un
            // object cache flush, un re-chequeo concurrente disparado por el panel,
            // una peculiaridad del host...). Si el ítem que nos piden no está en el
            // transient en este instante, lo reinyectamos con un re-chequeo real
            // que PRESERVA los premium. Así cada ítem se actualiza aunque el
            // transient se haya vaciado tras el ítem anterior.
            if ($reinstall) {
                // El actualizador toma el paquete del transient: el zip de la versión instalada, y luego se deja como estaba
                $updates = get_site_transient('update_plugins');
                $pending = $updates->response[$item] ?? null;
                $version = get_plugins()[$item]['Version'] ?? '';
                $updates->response[$item] = (object) [
                    'slug' => $wporg_slug, 'plugin' => $item, 'new_version' => $version,
                    'package' => 'https://downloads.wordpress.org/plugin/' . $wporg_slug . '.' . $version . '.zip',
                ];
                set_site_transient('update_plugins', $updates);
            } else {
                $this->ensure_pending_update_present($type, $item);
            }

            $was_active = false;
            $was_network_active = false;
            $new_item = $item;
            if ($type === 'plugin') {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
                $was_active = is_plugin_active($item);
                $was_network_active = is_multisite() && is_plugin_active_for_network($item);
                
                $upgrader = new \Plugin_Upgrader($upgrader_skin);
                // clear_update_cache=false: el flujo nativo, por defecto, borra
                // el transient update_plugins y llama wp_update_plugins() tras
                // cada update. En contexto REST eso tira las entradas de los
                // plugins premium (cuyo updater solo engancha en wp-admin), así
                // que el SIGUIENTE ítem del lote ya no está en el transient y
                // falla con "The plugin is at the latest version.". Lo evitamos
                // y, en éxito, retiramos el ítem a mano (forget_pending_update).
                $result = $upgrader->upgrade($item, ['clear_update_cache' => false]);
                if ($reinstall) {
                    $updates = get_site_transient('update_plugins');
                    if ($pending) {
                        $updates->response[$item] = $pending;
                    } else {
                        unset($updates->response[$item]);
                    }
                    set_site_transient('update_plugins', $updates);
                }

                // La ruta puede cambiar: un plugin de un solo archivo (hello.php)
                // se instala en una carpeta (hello-dolly/hello.php).
                if ($result === true && is_string($upgrader->plugin_info()) && $upgrader->plugin_info() !== '') {
                    $new_item = $upgrader->plugin_info();
                }
                // Reactivar SOLO si la actualización fue un éxito real.
                if ($was_active && $result === true) {
                    $this->keep_plugin_active($new_item, $was_network_active, $item);
                }
            } else {
                $upgrader = new \Theme_Upgrader($upgrader_skin);
                $result = $upgrader->upgrade($item, ['clear_update_cache' => false]);
            }

            // El actualizador nativo devuelve TRUE solo en éxito. Cualquier
            // otra cosa (WP_Error, false o NULL — p. ej. "Download failed.
            // Unauthorized") es un fallo: no debe reportarse como éxito, o
            // se ocultarían plugins desactualizados/vulnerables.
            if ($result !== true) {
                if ($backup_success) $wp_filesystem->delete($backup_path, true);

                $message = '';
                if (is_wp_error($result)) {
                    $message = $result->get_error_message();
                }
                if ($message === '' && method_exists($upgrader_skin, 'get_upgrade_messages')) {
                    $skin_messages = $upgrader_skin->get_upgrade_messages();
                    if (!empty($skin_messages)) {
                        // wp_strip_all_tags por si el mensaje trae HTML
                        $message = html_entity_decode(wp_strip_all_tags(end($skin_messages)), ENT_QUOTES, 'UTF-8');
                    }
                }
                if ($message === '') {
                    $message = 'La actualización no se completó (el actualizador no confirmó el éxito).';
                }

                // Detección GENERAL de fallos por licencia / falta de fuente
                // de actualización (no específica de ningún plugin): cubre
                // cualquier plugin premium sin licencia activa y los plugins
                // "GPL nulled" sin un servidor de updates válido.
                $requires_license = !$reinstall && $this->is_license_failure($type, $item, $message);
                if ($requires_license) {
                    $message = 'Requiere licencia activa o no tiene un paquete de actualización válido '
                        . '(posible plugin premium sin licencia o GPL nulled). Detalle: ' . $message;
                }

                // Persistir la razón del fallo para mostrarla en el panel
                // aunque se recargue (se limpia al actualizarse con éxito).
                if (!$reinstall) {
                    $this->record_update_failure($item, $requires_license, $message);
                }

                ob_end_clean();
                return rest_ensure_response([
                    'success' => false,
                    'message' => $message,
                    'requires_license' => $requires_license,
                ]);
            }

            $this->job_progress('Comprobando la web después de actualizar', true);
            $after = $this->probe_site($check_urls);
            $regressions = $this->probe_regressions($baseline, $after);
            $health_failed = !empty($regressions);
            $verified = count(array_filter($baseline, function ($p) { return $p['ok']; })) > 0;

            if ($health_failed) {
                if ($backup_success) {
                    // Restauración SEGURA: comprobar el resultado de copy_dir antes
                    // de borrar el backup. Si la restauración falla a medias (disco
                    // lleno, permisos, timeout) NO borramos el backup, para no dejar
                    // el directorio corrupto y sin red de recuperación (WSOD).
                    if ($new_item !== $item && dirname($new_item) !== '.') {
                        // Instalación nueva en otra ruta: fuera, y la vieja vuelve a su sitio
                        $wp_filesystem->delete(WP_PLUGIN_DIR . '/' . dirname($new_item), true);
                    }
                    $this->job_progress('La web falló: volviendo a la versión anterior', true);
                    if ($single_file) {
                        $restored = @copy($backup_path, $source_dir);
                        wp_opcache_invalidate($source_dir, true); // copy_dir ya lo hace por archivo
                    } else {
                        $wp_filesystem->delete($source_dir, true);
                        $restored = copy_dir($backup_path, $source_dir);
                    }

                    // Reactivar la versión vieja si estaba activo (best-effort).
                    if ($type === 'plugin' && $was_active) {
                        $this->keep_plugin_active($item, $was_network_active, $new_item);
                    }

                    // Purgar antes de cerrar el buffer: lo que imprima un plugin de caché no debe romper el JSON
                    $this->purge_all_caches();
                    ob_end_clean();

                    if ($restored === true) {
                        $wp_filesystem->delete($backup_path, true);
                        return rest_ensure_response([
                            'success'     => false,
                            'rolled_back' => true,
                            'regressions' => $regressions,
                            'message'     => 'El sitio falló tras actualizar (' . implode('; ', $regressions) . '). Se restauró la versión anterior.',
                        ]);
                    }

                    // La restauración falló: conservar el backup para recuperación manual.
                    return rest_ensure_response([
                        'success'     => false,
                        'rolled_back' => false,
                        'regressions' => $regressions,
                        'message'     => 'El sitio falló tras actualizar y la restauración automática falló. Copia conservada en ' . str_replace(ABSPATH, '', $backup_path) . ' para recuperarla a mano.',
                    ]);
                } else {
                    ob_end_clean();
                    return rest_ensure_response([
                        'success'     => false,
                        'rolled_back' => false,
                        'regressions' => $regressions,
                        'message'     => 'El sitio falló tras actualizar y no había copia previa que restaurar.',
                    ]);
                }
            }

            // keep_backup: el panel puede pedir volver atrás (cambio visual); se purga a los 7 días
            $kept = $backup_success && !empty($params['keep_backup']);
            if ($backup_success && !$kept) {
                $wp_filesystem->delete($backup_path, true);
            }

            // Éxito real: retirar el ítem del transient SIN reconstruirlo (no
            // usar wp_clean_*_cache, que en REST tira las updates premium del
            // resto del lote) y limpiar cualquier fallo previo persistido.
            if (!$reinstall) {
                $this->forget_pending_update($type, $item);
                $this->clear_update_failure($item);
            }

            // Purga defensiva de cachés de página/objeto/opcode ANTES de cerrar el
            // buffer, para que cualquier salida que suelte un plugin de caché quede
            // capturada y no rompa el JSON. Nunca altera el éxito del update.
            $purged_caches = $this->purge_all_caches();

            $debug_output = ob_get_clean();

            return rest_ensure_response([
                'success'  => true,
                'message'  => $reinstall ? 'Reinstalado desde wordpress.org (misma versión, código original).' : ucfirst($type) . ' actualizado correctamente.',
                'verified' => $verified, // false = ninguna sonda respondía antes: no se pudo comprobar
                'purged'   => $purged_caches,
                'item'     => $new_item,
                'backup'   => $kept ? basename($backup_path) : null,
            ]);
            
        } catch (\Throwable $e) {
            // \Throwable cubre también los Error fatales (TypeError, etc.)
            // que el actualizador nativo pudiera lanzar: se devuelven como
            // JSON controlado en vez de tumbar la petición con un 500.
            if (ob_get_level() > 0) {
                ob_get_clean();
            }
            return rest_ensure_response([
                'success' => false,
                'message' => 'Excepción en el worker: ' . $e->getMessage()
            ]);
        } finally {
            if (!empty($lock_held)) {
                delete_option(self::OPTION_UPDATE_LOCK);
            }
        }
    }

    /* ------------------------------------------------------------------
     * Comprobación del sitio antes/después de actualizar
     * ---------------------------------------------------------------- */

    /**
     * Plugin_Upgrader desactiva el plugin antes de actualizar. Se devuelve a la
     * lista de activos sin activate_plugin(), que incluye el archivo en esta
     * petición (con la versión vieja ya cargada falla en silencio y el plugin
     * quedaba desactivado, y las sondas probaban la web SIN la versión nueva).
     * Es lo mismo que hacen las actualizaciones automáticas de WordPress.
     */
    private function keep_plugin_active($item, $network, $replaces = null) {
        if ($network) {
            $sitewide = (array) get_site_option('active_sitewide_plugins', []);
            unset($sitewide[(string) $replaces]);
            $sitewide[$item] = time();
            update_site_option('active_sitewide_plugins', $sitewide);
            return;
        }
        $active = array_diff((array) get_option('active_plugins', []), [(string) $replaces]);
        $active[] = $item;
        $active = array_values(array_unique($active));
        sort($active);
        update_option('active_plugins', $active);
    }

    /** Oferta de actualización del núcleo pendiente (la del idioma del sitio va primero), o null. */
    private function core_offer() {
        if (!function_exists('get_core_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }
        foreach ((array) get_core_updates() as $offer) {
            if (is_object($offer) && isset($offer->response) && $offer->response === 'upgrade') {
                return $offer;
            }
        }
        return null;
    }

    /** ¿El paquete de idioma es para la versión instalada de su plugin/tema? */
    public function translation_matches_installed($t) {
        if (!is_object($t) || empty($t->type) || $t->type === 'core' || empty($t->version)) {
            return true;
        }
        if ($t->type === 'theme') {
            $theme = wp_get_theme($t->slug);
            return $theme->exists() && $theme->get('Version') === $t->version;
        }
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        foreach (get_plugins() as $file => $data) {
            if (dirname($file) === $t->slug || basename($file, '.php') === $t->slug) {
                return isset($data['Version']) && $data['Version'] === $t->version;
            }
        }
        return false;
    }

    /** ¿Está instalado? Impide que el panel pida rutas arbitrarias ("../"). */
    private function item_installed($type, $item) {
        if ($type === 'plugin') {
            if (!function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            return array_key_exists($item, get_plugins());
        }
        return array_key_exists($item, wp_get_themes());
    }

    /** Mutex por sitio: add_option es atómico (clave única en wp_options). */
    private function acquire_update_lock() {
        if (add_option(self::OPTION_UPDATE_LOCK, time(), '', 'no')) {
            return true;
        }
        // Bloqueo huérfano de un proceso que murió (timeout de PHP): caduca a los 15 min
        if (time() - (int) get_option(self::OPTION_UPDATE_LOCK) > 900) {
            delete_option(self::OPTION_UPDATE_LOCK);
            return add_option(self::OPTION_UPDATE_LOCK, time(), '', 'no');
        }
        return false;
    }

    /**
     * Carpeta de copias con nombre impredecible: en Plesk nginx sirve los
     * estáticos sin pasar por .htaccess, así que el "deny" no basta.
     */
    public static function backup_dir() {
        $name = get_option(self::OPTION_BACKUP_DIR);
        if (!$name || !preg_match('/^webminder-backups-[0-9a-f]{16}$/', $name)) {
            $name = 'webminder-backups-' . bin2hex(random_bytes(8));
            update_option(self::OPTION_BACKUP_DIR, $name, false);
        }
        $dir = WP_CONTENT_DIR . '/' . $name;
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        // La carpeta pública de versiones < 2.0 ya no se usa
        $legacy = WP_CONTENT_DIR . '/webminder-backups';
        if (is_dir($legacy) && !glob($legacy . '/*-backup-*')) {
            @rmdir($legacy);
        }
        return $dir;
    }

    /**
     * URLs a comprobar: la home + las que mande el panel (mismo host, máx. 5).
     * @return string[]
     */
    private function check_urls($extra) {
        $urls = [home_url('/')];
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        foreach (array_slice((array) $extra, 0, 5) as $u) {
            $u = esc_url_raw((string) $u);
            if ($u && wp_parse_url($u, PHP_URL_HOST) === $host) {
                $urls[] = $u;
            }
        }
        return array_values(array_unique($urls));
    }

    /**
     * Sondas: cada URL con un parámetro que evita la caché de página (si no,
     * LiteSpeed/WP Rocket devuelven la copia buena aunque PHP esté roto) y
     * admin-ajax, que carga todos los plugins en contexto de administración.
     * @return array<string,array{ok:bool,code:int,why:string}>
     */
    private function probe_site($urls) {
        $out = [];
        $nonce = bin2hex(random_bytes(4));
        $targets = [];
        foreach ($urls as $u) {
            $targets[$u] = add_query_arg('webminder_check', $nonce, $u);
        }
        $targets['admin-ajax'] = add_query_arg(['action' => 'webminder_probe', 'webminder_check' => $nonce], admin_url('admin-ajax.php'));

        foreach ($targets as $label => $url) {
            $r = wp_remote_get($url, [
                'timeout'     => 20,
                'redirection' => 3,
                'sslverify'   => false, // loopback: algunos hosts no validan su propio certificado
                'headers'     => ['Cache-Control' => 'no-cache', 'Pragma' => 'no-cache'],
            ]);
            if (is_wp_error($r)) {
                $out[$label] = ['ok' => false, 'code' => 0, 'why' => $r->get_error_message()];
                continue;
            }
            $code = (int) wp_remote_retrieve_response_code($r);
            $body = (string) wp_remote_retrieve_body($r);
            $why = '';
            if ($code >= 400) {
                $why = 'HTTP ' . $code;
            } elseif ($label === 'admin-ajax' && trim($body) !== 'webminder-ok') {
                $why = 'el admin no arranca';
            } elseif (preg_match('/There has been a critical error|Ha habido un error crítico|<b>(Fatal|Parse) error<\/b>/i', $body)) {
                $why = 'error crítico de WordPress en la página';
            }
            $out[$label] = ['ok' => $why === '', 'code' => $code, 'why' => $why];
        }
        return $out;
    }

    /**
     * Regresiones = sondas que antes daban bien y ahora no.
     * @return string[]
     */
    private function probe_regressions($before, $after) {
        $out = [];
        foreach ($before as $label => $b) {
            if ($b['ok'] && isset($after[$label]) && !$after[$label]['ok']) {
                $out[] = ($label === 'admin-ajax' ? 'wp-admin' : $label) . ': ' . $after[$label]['why'];
            }
        }
        return $out;
    }

    /* ------------------------------------------------------------------
     * Persistencia del último fallo de actualización por ítem
     * ---------------------------------------------------------------- */

    private function get_update_failures() {
        $failures = get_option(self::OPTION_UPDATE_FAILURES, []);
        return is_array($failures) ? $failures : [];
    }

    private function record_update_failure($item, $requires_license, $message) {
        $failures = $this->get_update_failures();
        $failures[$item] = [
            'requires_license' => (bool) $requires_license,
            'message'          => (string) $message,
            'at'               => gmdate('Y-m-d H:i:s'),
        ];
        update_option(self::OPTION_UPDATE_FAILURES, $failures, false);
    }

    private function clear_update_failure($item) {
        $failures = $this->get_update_failures();
        if (isset($failures[$item])) {
            unset($failures[$item]);
            update_option(self::OPTION_UPDATE_FAILURES, $failures, false);
        }
    }

    /**
     * Retira un ítem ya actualizado del transient de updates SIN reconstruirlo.
     * Imprescindible para los lotes ("Actualizar Todo Seguro"): un rebuild vía
     * wp_clean_*_cache()/wp_update_plugins() en contexto REST borra las updates
     * de plugins premium (su updater solo engancha en wp-admin), dejando al
     * resto del lote con "The plugin is at the latest version.".
     */
    private function forget_pending_update($type, $item) {
        $key = ($type === 'theme') ? 'update_themes' : 'update_plugins';
        $transient = get_site_transient($key);
        if (is_object($transient) && isset($transient->response[$item])) {
            unset($transient->response[$item]);
            set_site_transient($key, $transient);
        }
    }

    /**
     * Garantiza que el ítem a actualizar esté presente en el site transient de
     * updates ANTES de invocar al upgrader nativo. Imprescindible para los lotes:
     * el upgrader lee el paquete del transient y, si el ítem no está, lo trata
     * como "ya al día" y no actualiza (bug del lote: solo el 1er ítem se
     * actualizaba de forma fiable si algo vaciaba el transient entre ítems).
     *
     * Si el ítem falta, fuerza un re-chequeo real (maybe_refresh_updates(true))
     * que reconstruye el transient contra wordpress.org PRESERVANDO los premium
     * y reinyectando el propio worker. A prueba de fallos: cualquier excepción se
     * traga (si el ítem sigue sin aparecer, el upgrader devolverá su propio
     * "up to date" y el ítem simplemente se saltará, sin romper nada).
     */
    private function ensure_pending_update_present($type, $item) {
        try {
            $key = ($type === 'theme') ? 'update_themes' : 'update_plugins';
            $transient = get_site_transient($key);
            if (is_object($transient) && isset($transient->response[$item])) {
                return; // Ya presente: nada que hacer (caso normal).
            }
            // Falta: re-chequeo forzado preservando premium (reutiliza la lógica
            // ya probada de maybe_refresh_updates, que refresca plugins y temas).
            $this->maybe_refresh_updates(true);
        } catch (\Throwable $e) {
            // Nunca romper el update por el intento de recuperación.
        }
    }

    /**
     * Purga defensiva de cachés de PÁGINA/OBJETO/OPCODE tras un update exitoso:
     * OPcache, object cache y los plugins de caché de página más comunes. Cada
     * purga guardada y en su try/catch. NUNCA lanza ni altera el resultado del
     * update: si algo falla, simplemente esa caché no se purga.
     *
     * NO toca las cachés de detección de updates de WP (wp_clean_plugins_cache /
     * wp_clean_themes_cache): en REST tiran las updates premium del resto del
     * lote (ver el comentario en el punto de éxito de handle_update_item).
     *
     * TAMPOCO llama a wp_cache_flush(): en hosts con OBJECT CACHE PERSISTENTE
     * (Redis/Memcached) WordPress guarda los site transients (update_plugins /
     * update_themes) en ese object cache; un flush total los borraría y el
     * SIGUIENTE ítem del lote quedaría sin su entrada → "The plugin is at the
     * latest version." y se saltaría. Purgamos solo cachés de PÁGINA/OPCODE
     * concretas, nunca el object cache entero. (El upgrader nativo ya invalida
     * la caché de datos de plugins con wp_cache_delete('plugins','plugins').)
     *
     * @return string[] nombres de las cachés purgadas (para telemetría)
     */
    private function purge_all_caches() {
        $purged = [];
        $try = function ($label, callable $fn) use (&$purged) {
            try { $fn(); $purged[] = $label; } catch (\Throwable $e) {}
        };

        if (function_exists('opcache_reset') && ini_get('opcache.enable')) {
            $try('opcache', function () { @opcache_reset(); });
        }
        if (function_exists('rocket_clean_domain')) {
            $try('wp_rocket', function () { rocket_clean_domain(); });
        }
        if (function_exists('w3tc_flush_all')) {
            $try('w3_total_cache', function () { w3tc_flush_all(); });
        }
        if (function_exists('wp_cache_clear_cache')) {
            $try('wp_super_cache', function () { wp_cache_clear_cache(); });
        }
        if (class_exists('\\LiteSpeed\\Purge') || has_action('litespeed_purge_all')) {
            $try('litespeed', function () { do_action('litespeed_purge_all'); });
        }
        if (class_exists('autoptimizeCache')) {
            $try('autoptimize', function () { \autoptimizeCache::clearall(); });
        }
        if (class_exists('\\Elementor\\Plugin')) {
            $try('elementor', function () {
                $p = \Elementor\Plugin::$instance;
                if ($p && isset($p->files_manager) && is_object($p->files_manager) && method_exists($p->files_manager, 'clear_cache')) {
                    $p->files_manager->clear_cache();
                }
            });
        }
        return $purged;
    }

    /**
     * Determina, de forma general, si un fallo de actualización se debe a
     * licencia o a falta de una fuente de actualización válida.
     *
     * Aplica a CUALQUIER plugin/tema, no a uno concreto. Dos señales:
     *  1. El mensaje del actualizador menciona autorización/licencia/etc.
     *  2. (Estructural) El paquete de descarga está vacío o no proviene de
     *     downloads.wordpress.org — típico de plugins premium sin licencia
     *     activa o de copias "GPL nulled" cuyo servidor de updates rechaza
     *     o no entrega el ZIP.
     */
    /**
     * ¿El mensaje de error sugiere un fallo por licencia / autorización?
     * Compartido por plugins/temas y por las traducciones (los paquetes de
     * idioma de plugins premium fallan con el mismo tipo de error).
     */
    private function is_license_message($message) {
        $needle = strtolower((string) $message);
        // Solo señales FUERTES de licencia/autorización. Se quitaron hints
        // débiles ('invalid', '404', 'not found') que daban falsos positivos
        // en fallos de red puntuales (un plugin gratuito que no descarga por un
        // timeout no es "requiere licencia"). Los casos premium reales sin estas
        // palabras los sigue cazando la señal ESTRUCTURAL de is_license_failure
        // (sin paquete / paquete no-wordpress.org).
        $hints = [
            'unauthorized', 'forbidden', 'licen', 'sin licencia', 'expired',
            'caducad', 'subscription', 'suscrip', 'payment required',
            'no package', 'package not available', '401', '402', '403',
        ];
        foreach ($hints as $hint) {
            if ($hint !== '' && strpos($needle, $hint) !== false) {
                return true;
            }
        }
        return false;
    }

    private function is_license_failure($type, $item, $message) {
        if ($this->is_license_message($message)) {
            return true;
        }

        // Señal estructural: solo si el ítem está realmente listado como
        // actualización en el transient (si no lo está, no inferimos licencia
        // a partir del paquete: puede ser un fallo de red puntual).
        $transient = get_site_transient($type === 'plugin' ? 'update_plugins' : 'update_themes');
        if (is_object($transient) && isset($transient->response[$item])) {
            $entry = $transient->response[$item];
            $package = is_array($entry)
                ? (isset($entry['package']) ? $entry['package'] : '')
                : (isset($entry->package) ? $entry->package : '');

            // Sin paquete = no hay de dónde descargar (premium sin licencia / nulled).
            if ($package === '') {
                return true;
            }
            // Paquete de un proveedor externo (no wordpress.org) cuya descarga
            // ha fallado: muy probablemente licencia/autorización.
            if (strpos($package, '//downloads.wordpress.org/') === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Elimina respaldos de actualización con más de BACKUP_MAX_AGE segundos.
     * Cada respaldo tiene el sufijo "-backup-<timestamp>".
     */
    private function prune_old_backups($backup_dir) {
        if (!is_dir($backup_dir)) {
            return;
        }
        $now = time();
        foreach (glob($backup_dir . '/*-backup-*') ?: [] as $path) {
            if (preg_match('/-backup-(\d+)$/', $path, $m)) {
                if (($now - (int) $m[1]) > self::BACKUP_MAX_AGE) {
                    if (is_dir($path)) {
                        $this->rrmdir($path);
                    } else {
                        @unlink($path);
                    }
                }
            }
        }
    }

    /** Bytes que ocupa un directorio (0 si no se puede leer). */
    private static function dir_size($dir) {
        $total = 0;
        try {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
                $total += $f->getSize();
            }
        } catch (\Throwable $e) {
            // Permisos raros en el hosting: mejor un tamaño parcial que romper la lista de updates
        }
        return $total;
    }

    /**
     * Borra un directorio de forma recursiva (solo para los respaldos).
     */
    private function rrmdir($dir) {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Verificador principal de Firmas HMAC (Seguridad Paranoica).
     */
    public function verify_signature(\WP_REST_Request $request) {
        $secret = get_option(self::OPTION_CLIENT_SECRET);
        if (!$secret) {
            return new \WP_Error('unauthorized', 'El agente no está configurado correctamente.', ['status' => 401]);
        }

        // v2: la firma cubre método y ruta, no solo cuerpo+timestamp. Con la v1
        // una firma interceptada de /status (cuerpo "{}") valía para /disconnect.
        $signature = $request->get_header('X-Webminder-Signature-V2');
        $timestamp = (int) $request->get_header('X-Webminder-Timestamp');

        if (!$signature || !$timestamp) {
            return new \WP_Error('missing_headers', 'Faltan cabeceras de seguridad requeridas por Webminder (firma v2).', ['status' => 401]);
        }

        // Ventana anti-replay: una firma capturada deja de ser válida pasados
        // 15 minutos. Desde la v1.5 las peticiones las firma el servidor del
        // panel (reloj NTP), por lo que ya no aplica el problema de relojes
        // desfasados en PCs de usuarios que motivó eliminar la ventana de 60s.
        if (abs(time() - $timestamp) > self::TIMESTAMP_TOLERANCE) {
            return new \WP_Error('expired_signature', 'La petición ha expirado. Verifica el reloj del servidor.', ['status' => 401]);
        }

        $expected_signature = self::sign($request->get_method(), $request->get_route(), $timestamp, (string) $request->get_body(), $secret);

        // Limpiar prefijo 'sha256=' si está presente en la cabecera
        $clean_signature = preg_replace('/^sha256=/', '', (string) $signature);

        // (string) defensivo: hash_equals lanza TypeError si el 2º arg no es string.
        if (!hash_equals($expected_signature, $clean_signature)) {
            return new \WP_Error('invalid_signature', 'Firma criptográfica inválida. Acceso denegado.', ['status' => 401]);
        }

        // Anti-replay REAL (no solo caducidad): la firma es única por
        // (cuerpo, timestamp). Registrar la firma ya vista impide reproducir una
        // petición capturada dentro de la ventana de tolerancia (p. ej. un MITM
        // momentáneo que repita un /update o /db-clean). El panel firma cada
        // llamada con timestamp fresco, así que jamás reusa una firma legítima.
        $seen_key = 'ongoing_sig_' . hash('sha256', (string) $signature);
        if (get_transient($seen_key) !== false) {
            return new \WP_Error('replayed_signature', 'Petición duplicada (posible replay) rechazada.', ['status' => 401]);
        }
        set_transient($seen_key, 1, max(60, self::TIMESTAMP_TOLERANCE));

        // Throttle: el panel puede pollear /status muy seguido; evitar una
        // escritura a wp_options en cada petición. autoload='no' (3er arg) para
        // que la opción no se cargue en cada request del frontend del cliente.
        $last_sync = (int) get_option('ongoing_webminder_last_sync', 0);
        if (time() - $last_sync > 60) {
            update_option('ongoing_webminder_last_sync', time(), false);
        }

        // Las acciones de esta petición vienen del panel: el log de
        // actividad las atribuye a "Sistema Mantenimiento", no a un
        // usuario de WordPress.
        WebminderActivityLog::$is_panel_request = true;

        return true;
    }

    /**
     * Firma v2, compartida con el panel (AgenteComunicacionService::firmar)
     * y con el envío del log de actividad: METODO \n ruta \n timestamp \n cuerpo.
     */
    public static function sign($method, $route, $timestamp, $body, $secret) {
        return hash_hmac('sha256', strtoupper($method) . "\n" . $route . "\n" . (int) $timestamp . "\n" . $body, $secret);
    }
}

/**
 * Auto-actualizador del propio plugin.
 *
 * Consulta un manifiesto JSON remoto (alojado por la agencia) y, si hay
 * una versión más nueva, la inyecta en el flujo nativo de WordPress:
 * el sitio muestra la actualización en Plugins y puede instalarla o
 * auto-actualizarla como cualquier plugin del directorio oficial. Así
 * el worker se mantiene al día en todos los sitios sin intervención
 * manual. El chequeo se cachea 12 h, por lo que no añade peso real.
 */
class WebminderUpdater {

    const CACHE_KEY = 'ongoing_webminder_update_info';
    // URL del manifiesto (según la marca de esta build). Puede
    // sobreescribirse por sitio definiendo ONGOING_WEBMINDER_MANIFEST_URL
    // en wp-config.php.
    const DEFAULT_MANIFEST_URL = WebminderAgent::BRAND_MANIFEST_URL;

    private $basename;
    private $slug;
    private $version;

    public function __construct($basename, $slug, $version) {
        $this->basename = $basename;
        $this->slug = $slug;
        $this->version = $version;

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        add_action('upgrader_process_complete', [$this, 'clear_cache'], 10, 2);
    }

    private function manifest_url() {
        if (defined('ONGOING_WEBMINDER_MANIFEST_URL')) {
            return ONGOING_WEBMINDER_MANIFEST_URL;
        }
        // El panel vinculado sirve el manifiesto y el zip (artisan webminder:worker-zip).
        $panel = get_option(WebminderAgent::OPTION_PANEL_URL);
        return $panel ? $panel . '/webminder/worker/manifest.json' : self::DEFAULT_MANIFEST_URL;
    }

    /**
     * Obtiene el manifiesto remoto, con caché de 12 h y backoff de 2 h
     * ante fallos para no golpear el servidor en cada carga del admin.
     */
    private function get_remote_info() {
        $cached = get_transient(self::CACHE_KEY);
        if ($cached !== false) {
            return $cached === 'none' ? false : $cached;
        }

        $response = wp_remote_get($this->manifest_url(), [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            set_transient(self::CACHE_KEY, 'none', 2 * HOUR_IN_SECONDS);
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response));
        if (!is_object($data) || empty($data->version) || empty($data->download_url)) {
            set_transient(self::CACHE_KEY, 'none', 2 * HOUR_IN_SECONDS);
            return false;
        }

        // Defensa en profundidad (RCE de flota): el paquete que se instalará como
        // ESTE plugin (corre como admin en todos los sitios) debe venir por HTTPS
        // y del MISMO host que el manifiesto. Un manifiesto manipulado que apunte
        // el download_url a otro dominio se rechaza aunque el TLS sea válido.
        $manifest_host = wp_parse_url($this->manifest_url(), PHP_URL_HOST);
        $package_host  = wp_parse_url($data->download_url, PHP_URL_HOST);
        $package_scheme = wp_parse_url($data->download_url, PHP_URL_SCHEME);
        if ($package_scheme !== 'https' || empty($manifest_host) || $package_host !== $manifest_host) {
            set_transient(self::CACHE_KEY, 'none', 2 * HOUR_IN_SECONDS);
            return false;
        }

        set_transient(self::CACHE_KEY, $data, 12 * HOUR_IN_SECONDS);
        return $data;
    }

    /**
     * Inyecta la actualización disponible en el transient de WordPress.
     */
    public function check_for_update($transient) {
        if (!is_object($transient) || empty($transient->checked)) {
            return $transient;
        }

        $remote = $this->get_remote_info();
        if ($remote && version_compare($remote->version, $this->version, '>')) {
            $res = new \stdClass();
            $res->slug = $this->slug;
            $res->plugin = $this->basename;
            $res->new_version = $remote->version;
            $res->url = WebminderAgent::BRAND_URL;
            $res->package = $remote->download_url;
            $res->tested = isset($remote->tested) ? $remote->tested : '';
            $res->requires = isset($remote->requires) ? $remote->requires : '';
            $res->requires_php = isset($remote->requires_php) ? $remote->requires_php : '';
            $transient->response[$this->basename] = $res;
            unset($transient->no_update[$this->basename]);
        } else {
            // Ya estamos al día (o el manifiesto no ofrece una versión mayor):
            // retirar cualquier entrada nuestra que hubiera quedado obsoleta en
            // el transient de una versión anterior. Si no, tras actualizarnos el
            // panel y la página de Plugins de WP seguirían mostrando un update
            // fantasma "X → X" hasta que WordPress reconstruyera el transient.
            if (isset($transient->response[$this->basename])) {
                unset($transient->response[$this->basename]);
            }
        }

        return $transient;
    }

    /**
     * Rellena la ficha "Ver detalles" del plugin.
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }
        if (empty($args->slug) || $args->slug !== $this->slug) {
            return $result;
        }

        $remote = $this->get_remote_info();
        if (!$remote) {
            return $result;
        }

        $info = new \stdClass();
        $info->name = WebminderAgent::BRAND_NAME . ' - Mantenimiento Web';
        $info->slug = $this->slug;
        $info->version = $remote->version;
        $info->author = '<a href="' . WebminderAgent::BRAND_URL . '">' . WebminderAgent::BRAND_AUTHOR . '</a>';
        $info->homepage = WebminderAgent::BRAND_URL;
        $info->requires = isset($remote->requires) ? $remote->requires : '';
        $info->tested = isset($remote->tested) ? $remote->tested : '';
        $info->requires_php = isset($remote->requires_php) ? $remote->requires_php : '';
        $info->download_link = $remote->download_url;
        $info->last_updated = isset($remote->last_updated) ? $remote->last_updated : '';
        $info->sections = isset($remote->sections) ? (array) $remote->sections : ['description' => ''];

        return $info;
    }

    /**
     * Limpia la caché del manifiesto tras actualizar plugins.
     */
    public function clear_cache($upgrader, $options) {
        if (isset($options['action'], $options['type']) && $options['action'] === 'update' && $options['type'] === 'plugin') {
            delete_transient(self::CACHE_KEY);
        }
    }
}

/**
 * Log de actividad + alertas (Feature B).
 *
 * Registra eventos significativos del sitio (logins, plugins, temas,
 * usuarios, ajustes críticos, contenido) en una tabla local acotada y
 * los empuja TODOS al panel central (Supabase) en lotes vía WP-Cron /
 * flush oportunista. Los eventos CRÍTICOS se empujan de inmediato y
 * generan alerta + email en el servidor.
 *
 * Directiva primaria: nada de lo que ocurra aquí puede romper el sitio
 * del cliente. Cada handler está envuelto en try/catch (\Throwable) y
 * un fallo de red o de tabla simplemente deja el evento pendiente.
 */
class WebminderActivityLog {

    const TABLE = 'ongoing_webminder_activity';

    // Retención local: lo que llegue antes (decisión 2026-06-12)
    const RETENTION_DAYS = 90;
    const RETENTION_MAX_ROWS = 5000;

    const BATCH_SIZE = 100;
    const FLUSH_INTERVAL = 300;          // 5 min entre flushes
    const PUSH_TIMEOUT_CRITICAL = 3;     // push inmediato: no penalizar el request
    const PUSH_TIMEOUT_BATCH = 8;        // lotes: corren en cron, hay margen
    const CRON_HOOK = 'ongoing_webminder_flush_activity';

    const OPTION_LAST_FLUSH = 'ongoing_webminder_last_flush';
    const OPTION_FILE_EDIT_STATE = 'ongoing_webminder_file_edit_state';

    // Ruta de ingesta en el panel (la base sale del token de enlace).
    const INGEST_ROUTE = '/api/webminder/ingest';

    /** La petición actual viene firmada por el panel (HMAC) */
    public static $is_panel_request = false;

    /** Login en curso vía SSO 1-Click: null | 'preferred' | 'fallback' */
    public static $sso_login = null;

    /** @var WebminderActivityLog|null */
    private static $instance = null;

    /** Slug del propio worker (p. ej. "webminder-worker") */
    private $self_slug;

    /** IDs de usuario creados en este request (evita doble log con set_user_role) */
    private $just_registered = [];

    /** IDs de post ya logueados en este request (save_post puede dispararse 2 veces) */
    private $logged_posts = [];

    // Plugins cuya desactivación/eliminación compromete el mantenimiento:
    // seguridad, cache y backups (+ el propio worker). Se compara por slug.
    private static $critical_plugin_slugs = [
        'webminder-worker',
        'wordfence', 'sucuri-scanner', 'better-wp-security', 'ithemes-security-pro',
        'all-in-one-wp-security-and-firewall', 'solid-security',
        'wp-rocket', 'litespeed-cache', 'w3-total-cache', 'wp-super-cache',
        'wp-fastest-cache', 'autoptimize', 'wp-optimize',
        'updraftplus', 'backwpup', 'duplicator', 'all-in-one-wp-migration',
        'backupbuddy', 'solid-backups',
    ];

    // Opciones de WordPress cuyo cambio es SIEMPRE crítico
    private static $critical_options = [
        'permalink_structure'    => 'permalinks_changed',
        'siteurl'                => 'siteurl_changed',
        'home'                   => 'home_url_changed',
        'admin_email'            => 'admin_email_changed',
        'auto_update_core_major' => 'core_autoupdate_changed',
        'auto_update_core_minor' => 'core_autoupdate_changed',
        'auto_update_core_dev'   => 'core_autoupdate_changed',
    ];

    public static function boot($plugin_basename) {
        if (self::$instance === null) {
            self::$instance = new self($plugin_basename);
        }
        return self::$instance;
    }

    public static function instance() {
        return self::$instance !== null ? self::$instance : self::boot('webminder-worker/webminder-worker.php');
    }

    private function __construct($plugin_basename) {
        $this->self_slug = dirname($plugin_basename);

        // Accesos
        add_action('wp_login', [$this, 'on_login'], 10, 2);
        add_action('wp_logout', [$this, 'on_logout'], 10, 1);

        // Plugins
        add_action('activated_plugin', [$this, 'on_plugin_activated'], 10, 1);
        add_action('deactivated_plugin', [$this, 'on_plugin_deactivated'], 10, 1);
        add_action('deleted_plugin', [$this, 'on_plugin_deleted'], 10, 2);
        add_action('upgrader_process_complete', [$this, 'on_upgrader_complete'], 10, 2);

        // Temas y editor de archivos
        add_action('switch_theme', [$this, 'on_switch_theme'], 10, 3);
        add_action('wp_ajax_edit-theme-plugin-file', [$this, 'on_file_editor'], 0);

        // Usuarios
        add_action('user_register', [$this, 'on_user_register'], 10, 1);
        add_action('deleted_user', [$this, 'on_deleted_user'], 10, 3);
        add_action('set_user_role', [$this, 'on_set_user_role'], 10, 3);
        add_action('profile_update', [$this, 'on_profile_update'], 10, 2);

        // Ajustes críticos
        add_action('updated_option', [$this, 'on_option_updated'], 10, 3);

        // Contenido
        add_action('save_post', [$this, 'on_save_post'], 10, 3);
        add_action('wp_trash_post', [$this, 'on_trash_post'], 10, 1);
        add_action('before_delete_post', [$this, 'on_before_delete_post'], 10, 2);

        // Core
        add_action('_core_updated_successfully', [$this, 'on_core_updated'], 10, 1);

        // Entrega por lotes vía WP-Cron
        add_filter('cron_schedules', [$this, 'register_cron_schedule']);
        add_action(self::CRON_HOOK, [$this, 'cron_flush']);
        add_action('init', [$this, 'ensure_cron_scheduled'], 20);
    }

    /* ------------------------------------------------------------------
     * Infraestructura
     * ---------------------------------------------------------------- */

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function create_table() {
        global $wpdb;
        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            occurred_at DATETIME NOT NULL,
            actor_login VARCHAR(255) NULL,
            actor_email VARCHAR(255) NULL,
            actor_role VARCHAR(100) NULL,
            ip VARCHAR(64) NULL,
            action VARCHAR(100) NOT NULL,
            object_type VARCHAR(50) NULL,
            object_name VARCHAR(255) NULL,
            severity VARCHAR(20) NOT NULL DEFAULT 'info',
            details LONGTEXT NULL,
            synced TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY synced (synced),
            KEY occurred_at (occurred_at)
        ) $charset;");
    }

    public function register_cron_schedule($schedules) {
        if (!isset($schedules['ongoing_webminder_5min'])) {
            $schedules['ongoing_webminder_5min'] = [
                'interval' => self::FLUSH_INTERVAL,
                'display'  => 'Cada 5 minutos (Webminder)',
            ];
        }
        return $schedules;
    }

    public function ensure_cron_scheduled() {
        try {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time() + self::FLUSH_INTERVAL, 'ongoing_webminder_5min', self::CRON_HOOK);
            }
        } catch (\Throwable $e) {
            // El flush oportunista en /status cubre los sitios sin cron
        }
    }

    /** @return string|null null = sitio sin panel vinculado (los eventos esperan en cola) */
    private function ingest_url() {
        $panel = get_option(WebminderAgent::OPTION_PANEL_URL);
        return $panel ? $panel . self::INGEST_ROUTE : null;
    }

    /**
     * Quién hizo la acción. Tres orígenes posibles:
     *  - el panel (petición firmada HMAC) → "Sistema Mantenimiento"
     *  - un usuario logueado en WordPress → su login/email/rol
     *  - procesos del sistema (cron, CLI)  → etiqueta técnica
     */
    private function get_actor() {
        if (self::$is_panel_request) {
            return ['login' => 'Sistema Mantenimiento', 'email' => null, 'role' => 'system'];
        }
        if (function_exists('wp_get_current_user')) {
            $user = wp_get_current_user();
            if ($user && $user->exists()) {
                return [
                    'login' => $user->user_login,
                    'email' => $user->user_email,
                    'role'  => implode(',', (array) $user->roles),
                ];
            }
        }
        if (defined('DOING_CRON') && DOING_CRON) {
            return ['login' => 'WordPress (cron)', 'email' => null, 'role' => 'system'];
        }
        if (defined('WP_CLI') && WP_CLI) {
            return ['login' => 'WP-CLI', 'email' => null, 'role' => 'system'];
        }
        return ['login' => null, 'email' => null, 'role' => null];
    }

    /**
     * IP real del visitante, considerando Cloudflare y proxies.
     */
    private function get_ip() {
        // shortcut: solo REMOTE_ADDR; CF-Connecting-IP / X-Forwarded-For los falsea cualquiera.
        // Si un sitio va detrás de Cloudflare, validar REMOTE_ADDR contra sus rangos antes de confiar en la cabecera.
        $ip = isset($_SERVER['REMOTE_ADDR']) ? trim($_SERVER['REMOTE_ADDR']) : '';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }

    /**
     * Registra un evento. Punto de entrada único de todos los handlers.
     * Los críticos se empujan al panel de inmediato (timeout corto).
     */
    public function log($action, $args = []) {
        try {
            global $wpdb;
            $actor = isset($args['actor']) ? $args['actor'] : $this->get_actor();
            $severity = (isset($args['severity']) && $args['severity'] === 'critical') ? 'critical' : 'info';

            $inserted = $wpdb->insert(self::table_name(), [
                'occurred_at' => gmdate('Y-m-d H:i:s'),
                'actor_login' => isset($actor['login']) ? $actor['login'] : null,
                'actor_email' => isset($actor['email']) ? $actor['email'] : null,
                'actor_role'  => isset($actor['role']) ? $actor['role'] : null,
                'ip'          => $this->get_ip(),
                'action'      => substr((string) $action, 0, 100),
                'object_type' => isset($args['object_type']) ? substr((string) $args['object_type'], 0, 50) : null,
                'object_name' => isset($args['object_name']) ? substr((string) $args['object_name'], 0, 255) : null,
                'severity'    => $severity,
                'details'     => !empty($args['details']) ? wp_json_encode($args['details']) : null,
                'synced'      => 0,
            ]);

            if ($inserted && $severity === 'critical') {
                // Push inmediato del crítico (y de cualquier otro pendiente
                // que quepa en el lote). Si falla, queda en cola.
                $this->push_pending(self::PUSH_TIMEOUT_CRITICAL);
            }
        } catch (\Throwable $e) {
            // Jamás romper el sitio del cliente por culpa del log
        }
    }

    /* ------------------------------------------------------------------
     * Entrega al panel
     * ---------------------------------------------------------------- */

    /**
     * Flush acotado por intervalo: lo invocan /status (oportunista)
     * y cualquier otro punto de paso barato.
     */
    public function maybe_flush() {
        $last = (int) get_option(self::OPTION_LAST_FLUSH, 0);
        if (time() - $last < self::FLUSH_INTERVAL) {
            return;
        }
        $this->flush();
    }

    public function cron_flush() {
        try {
            $this->flush();
        } catch (\Throwable $e) {
            // Reintento en el siguiente tick del cron
        }
    }

    /**
     * Ciclo completo de mantenimiento del log: snapshot de
     * DISALLOW_FILE_EDIT, envío de pendientes y purga por retención.
     */
    public function flush() {
        update_option(self::OPTION_LAST_FLUSH, time(), false);
        $this->check_file_edit_state();

        // Hasta 5 lotes por flush para drenar backlogs (sitio que estuvo
        // sin red); en régimen normal será 1 lote o ninguno.
        for ($i = 0; $i < 5; $i++) {
            $sent = $this->push_pending(self::PUSH_TIMEOUT_BATCH);
            if ($sent < self::BATCH_SIZE) {
                break;
            }
        }

        $this->purge_old_events();
    }

    /**
     * Envía un lote de eventos pendientes. Devuelve cuántos aceptó el
     * servidor (0 si no había nada o si el push falló).
     */
    private function push_pending($timeout) {
        global $wpdb;
        $table = self::table_name();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE synced = 0 ORDER BY id ASC LIMIT %d",
            self::BATCH_SIZE
        ));
        if (empty($rows)) {
            return 0;
        }

        $client_id = get_option(WebminderAgent::OPTION_CLIENT_ID);
        $secret = get_option(WebminderAgent::OPTION_CLIENT_SECRET);
        $url = $this->ingest_url();
        if (!$client_id || !$secret || !$url) {
            return 0;
        }

        $events = [];
        foreach ($rows as $row) {
            $details = null;
            if (!empty($row->details)) {
                $decoded = json_decode($row->details, true);
                $details = is_array($decoded) ? $decoded : null;
            }
            $events[] = [
                'local_id'    => (int) $row->id,
                'occurred_at' => str_replace(' ', 'T', $row->occurred_at) . 'Z',
                'actor_login' => $row->actor_login,
                'actor_email' => $row->actor_email,
                'actor_role'  => $row->actor_role,
                'ip'          => $row->ip,
                'action'      => $row->action,
                'object_type' => $row->object_type,
                'object_name' => $row->object_name,
                'severity'    => $row->severity,
                'details'     => $details,
            ];
        }

        $body = wp_json_encode(['client_id' => $client_id, 'events' => $events]);
        $timestamp = time();
        $signature = WebminderAgent::sign('POST', self::INGEST_ROUTE, $timestamp, $body, $secret);

        $response = wp_remote_post($url, [
            'timeout' => $timeout,
            'headers' => [
                'Content-Type'             => 'application/json',
                'X-Webminder-Client'       => $client_id,
                'X-Webminder-Signature-V2' => $signature,
                'X-Webminder-Timestamp'    => (string) $timestamp,
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return 0;
        }

        $json = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($json) || empty($json['success']) || !isset($json['accepted']) || !is_array($json['accepted'])) {
            return 0;
        }

        $accepted = array_values(array_filter(array_map('intval', $json['accepted'])));
        if (!empty($accepted)) {
            $placeholders = implode(',', array_fill(0, count($accepted), '%d'));
            $wpdb->query($wpdb->prepare(
                "UPDATE $table SET synced = 1 WHERE id IN ($placeholders)",
                $accepted
            ));
        }

        return count($accepted);
    }

    /**
     * Retención local: 90 días o 5.000 filas, lo que llegue antes.
     * Prioridad absoluta: no engordar la BD del cliente.
     */
    private function purge_old_events() {
        global $wpdb;
        $table = self::table_name();

        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE synced = 1 AND occurred_at < UTC_TIMESTAMP() - INTERVAL %d DAY",
            self::RETENTION_DAYS
        ));

        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        if ($count > self::RETENTION_MAX_ROWS) {
            $excess = $count - self::RETENTION_MAX_ROWS;
            // El tope se aplica aunque haya filas sin sincronizar: la BD
            // del cliente manda. (Solo pasaría con el panel caído semanas.)
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $table ORDER BY id ASC LIMIT %d",
                $excess
            ));
        }
    }

    /**
     * DISALLOW_FILE_EDIT es una constante de wp-config.php: no hay hook
     * para su cambio. Se compara un snapshot guardado en cada flush.
     */
    private function check_file_edit_state() {
        $current = (defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT) ? 'disabled' : 'enabled';
        $stored = get_option(self::OPTION_FILE_EDIT_STATE);

        if ($stored === false) {
            // Primera ejecución: solo guardar el estado inicial
            update_option(self::OPTION_FILE_EDIT_STATE, $current, false);
            return;
        }
        if ($stored !== $current) {
            update_option(self::OPTION_FILE_EDIT_STATE, $current, false);
            $this->log(
                $current === 'enabled' ? 'file_edit_enabled' : 'file_edit_disabled',
                [
                    'severity' => $current === 'enabled' ? 'critical' : 'info',
                    'object_type' => 'setting',
                    'object_name' => 'DISALLOW_FILE_EDIT',
                    'details' => ['old' => $stored, 'new' => $current],
                    // No hay request de usuario asociado (cambio en wp-config)
                    'actor' => ['login' => null, 'email' => null, 'role' => null],
                ]
            );
        }
    }

    /* ------------------------------------------------------------------
     * Handlers de eventos
     * ---------------------------------------------------------------- */

    private function is_critical_plugin($plugin_file) {
        $slug = dirname((string) $plugin_file);
        if ($slug === '.') {
            $slug = basename((string) $plugin_file, '.php');
        }
        return in_array($slug, self::$critical_plugin_slugs, true);
    }

    private function plugin_label($plugin_file) {
        try {
            if (!function_exists('get_plugin_data')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $path = WP_PLUGIN_DIR . '/' . $plugin_file;
            if (file_exists($path)) {
                $data = get_plugin_data($path, false, false);
                if (!empty($data['Name'])) {
                    return $data['Name'];
                }
            }
        } catch (\Throwable $e) {
            // caer al slug
        }
        return (string) $plugin_file;
    }

    public function on_login($user_login, $user = null) {
        try {
            $details = [];
            if (self::$sso_login !== null) {
                $details['via'] = 'webminder_sso';
                $details['sso_user_selection'] = self::$sso_login;
            }
            $actor = ['login' => $user_login, 'email' => null, 'role' => null];
            if ($user instanceof \WP_User) {
                $actor['email'] = $user->user_email;
                $actor['role'] = implode(',', (array) $user->roles);
            }
            $this->log('login', [
                'object_type' => 'user',
                'object_name' => $user_login,
                'details' => $details ?: null,
                'actor' => $actor,
            ]);
        } catch (\Throwable $e) {
        }
    }

    public function on_logout($user_id = 0) {
        try {
            $user = $user_id ? get_userdata($user_id) : null;
            $this->log('logout', [
                'object_type' => 'user',
                'object_name' => $user ? $user->user_login : null,
                'actor' => $user ? [
                    'login' => $user->user_login,
                    'email' => $user->user_email,
                    'role'  => implode(',', (array) $user->roles),
                ] : null,
            ]);
        } catch (\Throwable $e) {
        }
    }

    public function on_plugin_activated($plugin) {
        try {
            $this->log('plugin_activated', [
                'object_type' => 'plugin',
                'object_name' => $this->plugin_label($plugin),
            ]);
        } catch (\Throwable $e) {
        }
    }

    public function on_plugin_deactivated($plugin) {
        try {
            $is_self = dirname((string) $plugin) === $this->self_slug;
            $this->log($is_self ? 'worker_deactivated' : 'plugin_deactivated', [
                'severity' => $this->is_critical_plugin($plugin) ? 'critical' : 'info',
                'object_type' => 'plugin',
                'object_name' => $this->plugin_label($plugin),
            ]);
        } catch (\Throwable $e) {
        }
    }

    public function on_plugin_deleted($plugin_file, $deleted = true) {
        try {
            if (!$deleted) {
                return;
            }
            $this->log('plugin_deleted', [
                'severity' => $this->is_critical_plugin($plugin_file) ? 'critical' : 'info',
                'object_type' => 'plugin',
                'object_name' => (string) $plugin_file,
            ]);
        } catch (\Throwable $e) {
        }
    }

    public function on_upgrader_complete($upgrader, $extra) {
        try {
            if (!is_array($extra) || !isset($extra['action'], $extra['type']) || $extra['action'] !== 'update') {
                return;
            }
            if ($extra['type'] === 'plugin') {
                $plugins = isset($extra['plugins']) && is_array($extra['plugins'])
                    ? $extra['plugins']
                    : (isset($extra['plugin']) ? [$extra['plugin']] : []);
                foreach ($plugins as $plugin) {
                    $this->log('plugin_updated', [
                        'object_type' => 'plugin',
                        'object_name' => $this->plugin_label($plugin),
                    ]);
                }
            } elseif ($extra['type'] === 'theme') {
                $themes = isset($extra['themes']) && is_array($extra['themes'])
                    ? $extra['themes']
                    : (isset($extra['theme']) ? [$extra['theme']] : []);
                foreach ($themes as $theme) {
                    $this->log('theme_updated', [
                        'object_type' => 'theme',
                        'object_name' => (string) $theme,
                    ]);
                }
            } elseif ($extra['type'] === 'core') {
                $this->log('core_updated', ['object_type' => 'core']);
            }
        } catch (\Throwable $e) {
        }
    }

    public function on_switch_theme($new_name, $new_theme = null, $old_theme = null) {
        try {
            $this->log('theme_switched', [
                'severity' => 'critical',
                'object_type' => 'theme',
                'object_name' => (string) $new_name,
                'details' => [
                    'old' => ($old_theme instanceof \WP_Theme) ? $old_theme->get('Name') : null,
                    'new' => (string) $new_name,
                ],
            ]);
        } catch (\Throwable $e) {
        }
    }

    public function on_file_editor() {
        try {
            // Se registra el INTENTO de edición (prioridad 0: corre antes
            // del handler nativo; no interfiere con él).
            $file = isset($_POST['file']) ? sanitize_text_field(wp_unslash($_POST['file'])) : '';
            $theme = isset($_POST['theme']) ? sanitize_text_field(wp_unslash($_POST['theme'])) : '';
            $plugin = isset($_POST['plugin']) ? sanitize_text_field(wp_unslash($_POST['plugin'])) : '';
            $this->log('file_editor_used', [
                'severity' => 'critical',
                'object_type' => $theme !== '' ? 'theme' : 'plugin',
                'object_name' => $file !== '' ? $file : ($theme !== '' ? $theme : $plugin),
                'details' => array_filter(['theme' => $theme, 'plugin' => $plugin, 'file' => $file]),
            ]);
        } catch (\Throwable $e) {
        }
    }

    public function on_user_register($user_id) {
        try {
            $this->just_registered[$user_id] = true;
            $user = get_userdata($user_id);
            $is_admin = $user && in_array('administrator', (array) $user->roles, true);
            $this->log($is_admin ? 'admin_user_created' : 'user_created', [
                'severity' => $is_admin ? 'critical' : 'info',
                'object_type' => 'user',
                'object_name' => $user ? $user->user_login : ('#' . $user_id),
                'details' => $user ? ['email' => $user->user_email, 'roles' => (array) $user->roles] : null,
            ]);
        } catch (\Throwable $e) {
        }
    }

    public function on_deleted_user($user_id, $reassign = null, $user = null) {
        try {
            $was_admin = ($user instanceof \WP_User) && in_array('administrator', (array) $user->roles, true);
            $this->log($was_admin ? 'admin_user_deleted' : 'user_deleted', [
                'severity' => $was_admin ? 'critical' : 'info',
                'object_type' => 'user',
                'object_name' => ($user instanceof \WP_User) ? $user->user_login : ('#' . $user_id),
                'details' => ($user instanceof \WP_User) ? ['email' => $user->user_email] : null,
            ]);
        } catch (\Throwable $e) {
        }
    }

    public function on_set_user_role($user_id, $role, $old_roles = []) {
        try {
            // El alta de usuario ya queda registrada por on_user_register
            if (isset($this->just_registered[$user_id])) {
                return;
            }
            $user = get_userdata($user_id);
            $gains_admin = $role === 'administrator' && !in_array('administrator', (array) $old_roles, true);
            $this->log($gains_admin ? 'admin_user_created' : 'user_role_changed', [
                'severity' => $gains_admin ? 'critical' : 'info',
                'object_type' => 'user',
                'object_name' => $user ? $user->user_login : ('#' . $user_id),
                'details' => ['old' => (array) $old_roles, 'new' => $role],
            ]);
        } catch (\Throwable $e) {
        }
    }

    public function on_profile_update($user_id, $old_user_data = null) {
        try {
            if (!($old_user_data instanceof \WP_User)) {
                return;
            }
            $new_user = get_userdata($user_id);
            if (!$new_user) {
                return;
            }
            $is_admin = in_array('administrator', (array) $new_user->roles, true);

            if ($old_user_data->user_email !== $new_user->user_email) {
                $this->log($is_admin ? 'admin_email_changed' : 'user_email_changed', [
                    'severity' => $is_admin ? 'critical' : 'info',
                    'object_type' => 'user',
                    'object_name' => $new_user->user_login,
                    'details' => ['old' => $old_user_data->user_email, 'new' => $new_user->user_email],
                ]);
            }

            // Contraseñas: solo se registra el cambio en admins (evidencia
            // de toma de control de cuenta); jamás valores.
            if ($is_admin && $old_user_data->user_pass !== $new_user->user_pass) {
                $this->log('admin_password_changed', [
                    'severity' => 'critical',
                    'object_type' => 'user',
                    'object_name' => $new_user->user_login,
                ]);
            }
        } catch (\Throwable $e) {
        }
    }

    public function on_option_updated($option, $old_value, $value) {
        try {
            if (!isset(self::$critical_options[$option])) {
                return;
            }
            $this->log(self::$critical_options[$option], [
                'severity' => 'critical',
                'object_type' => 'setting',
                'object_name' => $option,
                'details' => [
                    'old' => is_scalar($old_value) ? (string) $old_value : wp_json_encode($old_value),
                    'new' => is_scalar($value) ? (string) $value : wp_json_encode($value),
                ],
            ]);
        } catch (\Throwable $e) {
        }
    }

    /**
     * Etiqueta legible del tipo de contenido (nombre singular nativo de WP).
     * Cae al slug del post_type si no hay etiqueta. Nunca lanza.
     */
    private function post_type_label($post) {
        try {
            if ($post instanceof \WP_Post && function_exists('get_post_type_object')) {
                $obj = get_post_type_object($post->post_type);
                if ($obj && isset($obj->labels->singular_name) && $obj->labels->singular_name !== '') {
                    return (string) $obj->labels->singular_name;
                }
                return (string) $post->post_type;
            }
        } catch (\Throwable $e) {
            // best-effort
        }
        return '';
    }

    public function on_save_post($post_id, $post = null, $update = false) {
        try {
            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
                return;
            }
            if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
                return;
            }
            if (!($post instanceof \WP_Post)) {
                return;
            }
            $skip_types = ['revision', 'nav_menu_item', 'customize_changeset', 'custom_css', 'oembed_cache', 'user_request'];
            if (in_array($post->post_type, $skip_types, true)) {
                return;
            }
            // 'trash': wp_trash_post dispara también save_post con el post ya
            // en papelera; ese cambio lo registra on_trash_post (evita doble evento)
            if (in_array($post->post_status, ['auto-draft', 'inherit', 'trash'], true)) {
                return;
            }
            // save_post puede dispararse más de una vez por request
            if (isset($this->logged_posts[$post_id])) {
                return;
            }
            $this->logged_posts[$post_id] = true;

            $this->log($update ? 'post_updated' : 'post_created', [
                'object_type' => $post->post_type,
                'object_name' => $post->post_title !== '' ? $post->post_title : ('#' . $post_id),
                'details' => ['post_id' => $post_id, 'status' => $post->post_status, 'object_label' => $this->post_type_label($post)],
            ]);
        } catch (\Throwable $e) {
        }
    }

    public function on_trash_post($post_id) {
        try {
            $post = get_post($post_id);
            if (!$post || in_array($post->post_type, ['revision', 'nav_menu_item'], true)) {
                return;
            }
            $this->log('post_trashed', [
                'object_type' => $post->post_type,
                'object_name' => $post->post_title !== '' ? $post->post_title : ('#' . $post_id),
                'details' => ['post_id' => $post_id, 'object_label' => $this->post_type_label($post)],
            ]);
        } catch (\Throwable $e) {
        }
    }

    public function on_before_delete_post($post_id, $post = null) {
        try {
            if (!($post instanceof \WP_Post)) {
                $post = get_post($post_id);
            }
            if (!$post || in_array($post->post_type, ['revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache'], true)) {
                return;
            }
            $this->log('post_deleted', [
                'object_type' => $post->post_type,
                'object_name' => $post->post_title !== '' ? $post->post_title : ('#' . $post_id),
                'details' => ['post_id' => $post_id, 'object_label' => $this->post_type_label($post)],
            ]);
        } catch (\Throwable $e) {
        }
    }

    public function on_core_updated($wp_version) {
        try {
            $this->log('core_updated', [
                'object_type' => 'core',
                'object_name' => 'WordPress ' . $wp_version,
            ]);
        } catch (\Throwable $e) {
        }
    }
}

// Interceptar la petición de SSO en la inicialización (fuera de la REST API)
add_action('init', function() {
    if (isset($_GET['ongoing_sso'])) {
        $token = sanitize_text_field($_GET['ongoing_sso']);
        $token_key = 'ongoing_sso_token_' . hash('sha256', $token);

        // El transient guarda el ID de la cuenta de la agencia elegida al emitir el token
        $user_id = (int) get_transient($token_key);
        if ($user_id) {
            // Destruir el token inmediatamente (One-Time Use)
            delete_transient($token_key);

            $admin_user = get_userdata($user_id);
            // Revalidar: pudo perder el rol en los 30 s de vida del token
            if ($admin_user && in_array('administrator', (array) $admin_user->roles, true)
                && in_array(strtolower($admin_user->user_email), WebminderAgent::SSO_PREFERRED_EMAILS, true)) {
                // Marcar el login como SSO para que el log de actividad
                // lo registre como sesión iniciada vía panel.
                WebminderActivityLog::$sso_login = 'preferred';

                // Forzar login: limpiar cualquier cookie de sesión previa
                // antes de emitir la nueva (evita fijación de sesión)
                wp_clear_auth_cookie();
                wp_set_current_user($admin_user->ID, $admin_user->user_login);
                wp_set_auth_cookie($admin_user->ID, true);
                do_action('wp_login', $admin_user->user_login, $admin_user);

                // Evitar que el token (que viaja en la URL ?ongoing_sso=) se filtre
                // por la cabecera Referer al navegar a wp-admin y sus recursos.
                if (!headers_sent()) {
                    header('Referrer-Policy: no-referrer');
                }

                // Redirigir al dashboard y morir
                wp_redirect(admin_url());
                exit;
            } else {
                wp_die('Error Webminder: la cuenta de la agencia ya no es administradora.');
            }
        } else {
            wp_die('Error Webminder: El enlace de acceso automático es inválido o ha caducado.');
        }
    }
});

// Log de actividad (debe arrancar antes que el agente: verify_signature
// y handle_status lo referencian)
WebminderActivityLog::boot(plugin_basename(__FILE__));

// Al desactivar: limpiar el cron de flush. El evento "worker_deactivated"
// ya lo registra (y empuja) el hook 'deactivated_plugin', que corre antes.
register_deactivation_hook(__FILE__, function () {
    try {
        wp_clear_scheduled_hook(WebminderActivityLog::CRON_HOOK);
    } catch (\Throwable $e) {
        // nunca impedir la desactivación
    }
});

// Arrancar motor
new WebminderAgent();

// Auto-actualizador del plugin
new WebminderUpdater(plugin_basename(__FILE__), 'webminder-worker', WebminderAgent::VERSION);
