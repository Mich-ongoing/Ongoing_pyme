<?php

namespace Tests\Feature;

use App\Facturacion\BorradorFactura;
use App\Facturacion\HojaFacturacion;
use App\Facturacion\Holded;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FacturacionTest extends TestCase
{
    use RefreshDatabase;

    /** Misma forma que la hoja real (exportada a CSV): año encima, mes debajo, formato español. */
    public const FILAS = [
        ['', "KPI's ANALYSIS"],
        ['', '', '', '', '2.026', '2.026', '2.026', 'FY'],
        ['', 'CLIENT NAME', 'SL NAME', 'REVENUE LINE', '9', '10', '11', '2026'],
        ['', 'Acme', 'ACME, S.L.', 'MRR SEO', '800', '800', '800', ''],
        ['', 'Acme', 'ACME, S.L.', 'MRR Paid media', '1.200', '1.200', '1.450', ''],
        ['', 'Acme', 'ACME, S.L.', 'Invoiced 2024', '', '', '500', ''],
        ['', 'Beta', 'Beta Serveis SL', 'ONE-OFF Kids Digitales SEO', '', '', '167', ''],
        ['', 'Gamma', '#N/A', 'Maintainment web', '', '', '40', ''],
        ['', 'Delta', 'Delta SL', 'MRR SEO', '300', '0', '', ''],
        ['', 'TOTAL REVENUE', '-', '-', '2.300', '2.000', '2.957', ''],
        ['', 'Acme', 'ACME, S.L.', 'MRR SEO', '', '', '25', ''], // bloque de horas: no es facturación
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['facturacion.holded_key' => 'clave-prueba']);
        // La hoja de Google, sustituida por las filas de arriba
        $this->app->instance(HojaFacturacion::class, new class extends HojaFacturacion {
            public function filas(): array { return FacturacionTest::FILAS; }
        });
    }

    private function holded(): void
    {
        Http::fake([
            'api.holded.com/api/v2/contacts*' => Http::response(['items' => [
                ['id' => 'c-acme', 'name' => 'Acme SL', 'trade_name' => 'Acme'],
                ['id' => 'c-beta', 'name' => 'BETA SERVEIS, S.L.'],
            ], 'cursor' => null, 'has_more' => false]),
            'api.holded.com/api/v2/invoices' => Http::sequence()->push(['id' => 'f-1'], 201)->push(['id' => 'f-2'], 201),
        ]);
    }

    public function test_lee_las_lineas_del_mes_con_el_importe_anterior(): void
    {
        $l = HojaFacturacion::lineas(self::FILAS, 2026, 11);

        $this->assertSame(['MRR SEO', 'MRR Paid media', 'ONE-OFF Kids Digitales SEO', 'Maintainment web'], array_column($l, 'linea'));
        $this->assertSame(1450.0, $l[1]['importe']);
        $this->assertSame(1200.0, $l[1]['anterior']);
        $this->assertSame('ACME, S.L.', $l[0]['sociedad']);
        $this->assertContains('Delta SL', array_column(HojaFacturacion::lineas(self::FILAS, 2026, 9), 'sociedad'));
    }

    public function test_lee_la_hoja_del_apps_script_con_el_token(): void
    {
        config(['facturacion.apps_script_url' => 'https://script.google.com/macros/s/abc/exec', 'facturacion.apps_script_token' => 'secreto']);
        Http::fake([
            'script.google.com/*' => Http::sequence()->push(['values' => [['', 'CLIENT NAME']]])->push(['error' => 'No autorizado']),
        ]);

        $this->assertSame([['', 'CLIENT NAME']], (new HojaFacturacion)->filas());
        Http::assertSent(fn (Request $r) => $r['token'] === 'secreto');
        $this->expectExceptionMessage('No autorizado');
        (new HojaFacturacion)->filas();
    }

    public function test_numeros_y_nombres(): void
    {
        $this->assertSame(1200.5, HojaFacturacion::numero('1.200,50'));
        $this->assertSame(2026.0, HojaFacturacion::numero('2.026'));
        $this->assertSame(167.0, HojaFacturacion::numero(167));
        $this->assertSame(Holded::normalizar('BETA SERVEIS, S.L.'), Holded::normalizar('Beta Serveis SL'));
        $this->assertSame(Holded::normalizar('Pixel Comunicació S.L.U.'), Holded::normalizar('PIXEL COMUNICACIO SLU'));
    }

    public function test_el_usuario_de_facturacion_no_entra_en_el_resto_del_panel(): void
    {
        $this->holded();
        $u = User::factory()->create(['role' => 'facturacion']);

        $this->actingAs($u)->get('/webminder-panel')->assertRedirect(route('facturacion.index'));
        $this->actingAs($u)->get('/webminder-panel/sitios')->assertRedirect(route('facturacion.index'));
        $this->actingAs($u)->post('/webminder-panel/cerrojo/todos')->assertRedirect(route('facturacion.index'));
        $this->actingAs($u)->get('/facturacion?mes=2026-11')->assertOk()->assertSee('ACME, S.L.')->assertSee('Kit Digital');

        $otro = User::factory()->create(['role' => 'lector']);
        $this->actingAs($otro)->get('/facturacion')->assertForbidden();
    }

    public function test_crea_borradores_una_sola_vez_y_con_los_importes_de_la_hoja(): void
    {
        $this->holded();
        $u = User::factory()->create(['role' => 'facturacion']);

        $this->actingAs($u)->get('/facturacion?mes=2026-11')->assertOk()
            ->assertSee('La hoja no tiene sociedad') // Gamma está en #N/A
            ->assertSee('cambia respecto al mes anterior');

        $this->actingAs($u)->post('/facturacion/crear', ['mes' => '2026-11', 'sociedades' => ['ACME, S.L.', '#N/A']])
            ->assertRedirect(route('facturacion.index', ['mes' => '2026-11']));

        $b = BorradorFactura::sole();
        $this->assertSame(['ACME, S.L.', 'f-1', 2250.0], [$b->sociedad, $b->holded_id, $b->total]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/invoices')
            && $r['contact_id'] === 'c-acme'
            && $r['items'][1]['price'] == 1450
            && $r['items'][1]['name'] === 'Gestión de campañas de publicidad (Paid Media)'
            && $r['items'][1]['taxes'] === ['s_iva_21']
            && $r['items'][0]['description'] === 'Noviembre 2026'
            && $r->header('Authorization')[0] === 'Bearer clave-prueba');

        // Segundo envío de la misma factura: no se crea otra
        $this->actingAs($u)->post('/facturacion/crear', ['mes' => '2026-11', 'sociedades' => ['ACME, S.L.']])->assertSessionHas('error');
        $this->assertSame(1, BorradorFactura::count());
        Http::assertSentCount(2); // contactos (luego en caché) + una sola factura
    }
}
