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

        (new ActualizarSitio($e->id))->handle(app(AgenteComunicacionService::class));

        $e->refresh();
        $this->assertSame('con_errores', $e->estado);
        $this->assertSame('wp-content/x/db.sql.gz', $e->archivo_copia_bd);
        $this->assertSame(['ok', 'revertido', 'ok'], array_column($e->items, 'estado'));
    }

    public function test_si_la_copia_de_bd_falla_no_se_actualiza_nada(): void
    {
        Http::fake(['*' => Http::response(['code' => 'dump_error', 'message' => 'disco lleno'], 500)]);
        $e = $this->lote(['a/a.php']);

        (new ActualizarSitio($e->id))->handle(app(AgenteComunicacionService::class));

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

        (new ActualizarSitio($e->id))->handle(app(AgenteComunicacionService::class));

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

        (new ActualizarSitio($e->id))->handle(app(AgenteComunicacionService::class));

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

        (new ActualizarSitio($e->id))->handle(app(AgenteComunicacionService::class));

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
}
