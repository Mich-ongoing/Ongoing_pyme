<?php

namespace Tests\Feature;

use App\Models\User;
use App\Webminder\Panel\Models\AligeraLicencia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AligeraLicenciasTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETO = 'whsec_prueba';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mail.from.address' => 'watch@ongoing.test',
            'services.aligera.stripe_webhook' => self::SECRETO,
            'services.aligera.enlaces' => ['plink_pro' => 'profesional'],
            'services.avisos.email' => 'michell@ongoing.test',
        ]);
    }

    /** Destinatario de cada correo enviado (transporte array de los tests). */
    private function para(): array
    {
        return app('mailer')->getSymfonyTransport()->messages()->map(fn ($m) => $m->getOriginalMessage()->getTo()[0]->getAddress())->all();
    }

    private function stripe(array $evento, ?string $secreto = self::SECRETO, ?int $t = null)
    {
        $cuerpo = json_encode($evento);
        $t ??= time();
        $firma = hash_hmac('sha256', $t . '.' . $cuerpo, $secreto);

        return $this->call('POST', '/api/aligera/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => "t={$t},v1={$firma}", 'CONTENT_TYPE' => 'application/json'], $cuerpo);
    }

    private function compra(string $sub = 'sub_1'): array
    {
        return ['type' => 'checkout.session.completed', 'data' => ['object' => [
            'id' => 'cs_1', 'mode' => 'subscription', 'subscription' => $sub, 'customer' => 'cus_1',
            'payment_link' => 'plink_pro', 'customer_details' => ['email' => 'ana@acme.test'],
        ]]];
    }

    public function test_el_pago_crea_la_licencia_del_plan_y_manda_la_clave_una_sola_vez(): void
    {
        $this->stripe($this->compra())->assertOk();
        $this->stripe($this->compra())->assertOk(); // Stripe reenvía el mismo evento

        $l = AligeraLicencia::sole();
        $this->assertSame(['profesional', 3, 'ana@acme.test', 'sub_1'], [$l->plan, $l->sitios_max, $l->email, $l->stripe_suscripcion]);
        $this->assertMatchesRegularExpression('/^ALG-[0-9A-F]{4}(-[0-9A-F]{4}){3}$/', $l->clave);
        $this->assertTrue($l->caduca_en->between(now()->addYear(), now()->addYear()->addDays(8)));
        $this->assertSame(['ana@acme.test', 'michell@ongoing.test'], $this->para()); // la clave al cliente y el aviso de venta
    }

    public function test_sin_firma_valida_o_con_firma_vieja_no_hace_nada(): void
    {
        $this->stripe($this->compra(), 'whsec_otro')->assertStatus(400);
        $this->stripe($this->compra(), self::SECRETO, time() - 600)->assertStatus(400);
        config(['services.aligera.stripe_webhook' => '']);
        $this->stripe($this->compra(), '')->assertStatus(400);
        $this->assertSame(0, AligeraLicencia::count());
    }

    public function test_un_pago_de_otro_enlace_avisa_y_no_crea_licencia(): void
    {
        $evento = $this->compra();
        $evento['data']['object']['payment_link'] = 'plink_desconocido';
        $this->stripe($evento)->assertOk();
        $this->assertSame(0, AligeraLicencia::count());
        $this->assertSame(['michell@ongoing.test'], $this->para());
    }

    public function test_la_renovacion_alarga_y_el_fin_de_la_suscripcion_la_caduca(): void
    {
        $this->stripe($this->compra())->assertOk();
        $fin = now()->addYears(2)->timestamp;
        // API nueva de Stripe: el id de la suscripción va en parent.subscription_details
        $this->stripe(['type' => 'invoice.paid', 'data' => ['object' => [
            'parent' => ['subscription_details' => ['subscription' => 'sub_1']],
            'lines' => ['data' => [['period' => ['start' => now()->addYear()->timestamp, 'end' => $fin]]]],
        ]]])->assertOk();
        $this->assertSame($fin + 7 * 86400, AligeraLicencia::sole()->caduca_en->timestamp);

        $this->stripe(['type' => 'customer.subscription.deleted', 'data' => ['object' => ['id' => 'sub_1', 'ended_at' => time() - 10]]])->assertOk();
        $this->assertFalse(AligeraLicencia::sole()->vigente());
    }

    public function test_activar_respeta_el_limite_de_webs_y_cuenta_www_como_la_misma(): void
    {
        $l = AligeraLicencia::nueva('ana@acme.test', 'personal', now()->addYear());

        $this->postJson('/api/aligera/activar', ['clave' => strtolower($l->clave), 'sitio' => 'https://www.Acme.es/'])->assertOk()->assertJson(['licencia' => 'activa', 'sitios_usados' => 1]);
        $this->postJson('/api/aligera/activar', ['clave' => $l->clave, 'sitio' => 'https://acme.es/blog'])->assertOk();
        $this->postJson('/api/aligera/activar', ['clave' => $l->clave, 'sitio' => 'https://otra.es'])->assertStatus(403);

        $this->postJson('/api/aligera/desactivar', ['clave' => $l->clave, 'sitio' => 'https://acme.es'])->assertOk();
        $this->postJson('/api/aligera/activar', ['clave' => $l->clave, 'sitio' => 'https://otra.es'])->assertOk();

        $this->postJson('/api/aligera/activar', ['clave' => 'ALG-NO-EXISTE', 'sitio' => 'https://otra.es'])->assertStatus(404);
        $l->update(['caduca_en' => now()->subDay()]);
        $this->postJson('/api/aligera/activar', ['clave' => $l->clave, 'sitio' => 'https://otra.es'])->assertStatus(403);
    }

    public function test_solo_una_licencia_vigente_y_activada_en_esa_web_recibe_el_paquete(): void
    {
        File::ensureDirectoryExists(storage_path('app/aligera'));
        File::put(storage_path('app/aligera/manifest.json'), json_encode(['version' => '0.2.0']));
        File::put(storage_path('app/aligera/aligera-pro.zip'), 'zip');
        $l = AligeraLicencia::nueva('ana@acme.test', 'personal', now()->addYear());
        $pide = fn (string $sitio) => $this->getJson('/api/aligera/actualizacion?' . http_build_query(['clave' => $l->clave, 'sitio' => $sitio, 'version' => '0.1.0']));

        $pide('https://acme.es')->assertOk()->assertJson(['licencia' => 'no_activada', 'version' => '0.2.0'])->assertJsonMissing(['download_url' => route('aligera.descargar', $l->clave)]);
        $this->postJson('/api/aligera/activar', ['clave' => $l->clave, 'sitio' => 'https://acme.es']);
        $pide('https://acme.es')->assertOk()->assertJson(['licencia' => 'activa', 'download_url' => route('aligera.descargar', $l->clave)]);
        $this->assertSame('0.1.0', $l->activaciones()->sole()->version);
        $this->get(route('aligera.descargar', $l->clave))->assertOk();

        $l->update(['caduca_en' => now()->subDay()]);
        $this->assertArrayNotHasKey('download_url', $pide('https://acme.es')->assertJson(['licencia' => 'caducada'])->json());
        $this->get(route('aligera.descargar', $l->clave))->assertStatus(403);
    }

    public function test_publicar_lee_la_version_del_zip_de_la_pro(): void
    {
        $zip = tempnam(sys_get_temp_dir(), 'alg') . '.zip';
        $z = new \ZipArchive();
        $z->open($zip, \ZipArchive::CREATE);
        $z->addFromString('aligera-pro/aligera.php', "<?php\n/**\n * Plugin Name: Aligera Pro\n * Version:           0.3.1\n * Requires PHP:      7.4\n */");
        $z->close();

        $this->artisan('aligera:publicar', ['zip' => $zip, '--cambios' => 'Arreglos'])->assertSuccessful();
        $m = json_decode(File::get(storage_path('app/aligera/manifest.json')), true);
        $this->assertSame(['0.3.1', '7.4', 'Arreglos'], [$m['version'], $m['requires_php'], $m['sections']['changelog']]);
    }

    public function test_desde_el_panel_se_crea_se_anula_y_se_libera(): void
    {
        $this->actingAs(User::factory()->create());
        $this->post(route('webminder-panel.aligera.store'), ['email' => 'yo@ongoing.test', 'plan' => 'agencia', 'meses' => 12])->assertRedirect();
        $l = AligeraLicencia::sole();
        $this->assertSame(25, $l->sitios_max);
        $this->assertSame([], $this->para()); // sin la casilla «Enviarle la clave»

        $a = $l->activaciones()->create(['dominio' => 'acme.es']);
        $this->get(route('webminder-panel.aligera.index', ['q' => 'acme.es']))->assertOk()->assertSee($l->clave);
        $this->post(route('webminder-panel.aligera.liberar', $a))->assertRedirect();
        $this->post(route('webminder-panel.aligera.anular', $l))->assertRedirect();
        $this->assertSame(0, $l->activaciones()->count());
        $this->assertFalse($l->fresh()->vigente());
    }
}
