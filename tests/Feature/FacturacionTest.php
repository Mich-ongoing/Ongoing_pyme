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
        ['', 'Beta', 'Beta Serveis SL', 'ONE-OFF RRSS', '', '', '167', ''],
        ['', 'Gamma', '#N/A', 'Maintainment web', '', '', '40', ''],
        ['', 'Delta', 'Delta SL', 'MRR SEO', '300', '0', '', ''],
        ['', 'Acme', 'ACME, S.L.', 'ONE-OFF Kids Digitales SEO', '', '', '2.000', ''], // Kit Digital: no se factura aquí
        ['', 'Epsilon', 'Epsilon SL', 'ONE-OFF Kids Digitales', '', '', '1.000', ''],
        ['', 'TOTAL REVENUE', '-', '-', '2.300', '2.000', '2.957', ''],
        ['', 'Acme', 'ACME, S.L.', 'MRR SEO', '', '', '25', ''], // bloque de horas: no es facturación
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['facturacion.holded_key' => 'clave-prueba']);
        // La hoja de Google, sustituida por las filas de arriba
        $this->app->instance(HojaFacturacion::class, new class extends HojaFacturacion {
            public function filas(): array
            {
                $this->comentarios = ['3,6' => "Campaña  de\nnoviembre"]; // celda de 11/2026 de «MRR SEO» de Acme

                return FacturacionTest::FILAS;
            }
        });
    }

    private function holded(): void
    {
        Http::fake([
            'api.holded.com/api/v2/contacts*' => Http::response(['items' => [
                ['id' => 'c-acme', 'name' => 'Acme SL', 'trade_name' => 'Acme'],
                ['id' => 'c-beta', 'name' => 'BETA SERVEIS, S.L.', 'code' => 'B111'],
                ['id' => 'c-beta2', 'name' => 'Beta Serveis SL', 'code' => 'B222'],
                ['id' => 'c-gamma', 'name' => 'Gamma Studio SL'],
            ], 'cursor' => null, 'has_more' => false]),
            'api.holded.com/api/v2/invoices' => Http::sequence()->push(['id' => 'f-1'], 201)->push(['id' => 'f-2'], 201),
        ]);
    }

    public function test_lee_las_lineas_del_mes_con_el_importe_anterior(): void
    {
        $l = HojaFacturacion::lineas(self::FILAS, 2026, 11);

        $this->assertSame(['MRR SEO', 'MRR Paid media', 'ONE-OFF RRSS', 'Maintainment web'], array_column($l, 'linea'));
        $this->assertSame(1450.0, $l[1]['importe']);
        $this->assertSame(1200.0, $l[1]['anterior']);
        $this->assertSame('ACME, S.L.', $l[0]['sociedad']);
        $this->assertContains('Delta SL', array_column(HojaFacturacion::lineas(self::FILAS, 2026, 9), 'sociedad'));
    }

    public function test_lee_la_hoja_del_apps_script_con_el_token(): void
    {
        config(['facturacion.apps_script_url' => 'https://script.google.com/macros/s/abc/exec', 'facturacion.apps_script_token' => 'secreto']);
        Http::fake([
            'script.google.com/*' => Http::sequence()->push(['values' => [['', 'CLIENT NAME']], 'comentarios' => ['0,1' => 'hola']])->push(['error' => 'No autorizado']),
        ]);

        $hoja = new HojaFacturacion;
        $this->assertSame([['', 'CLIENT NAME']], $hoja->filas());
        $this->assertSame(['0,1' => 'hola'], $hoja->comentarios);
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
        $this->actingAs($u)->get('/facturacion?mes=2026-11')->assertOk()->assertSee('ACME, S.L.')->assertDontSee('Epsilon')->assertDontSee('Kids Digitales');

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
            && $r['items'][1]['name'] === 'MRR Paid media'
            && $r['items'][1]['account'] === config('facturacion.cuentas')['MRR Paid media']
            && $r['items'][1]['description'] === 'Gestión de campañas de publicidad (Paid Media)'
            && $r['items'][0]['description'] === 'Campaña de noviembre' // el comentario de la celda manda
            && $r['items'][1]['taxes'] === ['s_iva_21']
            && $r['items'][0]['units'] === 1
            && $r->header('Authorization')[0] === 'Bearer clave-prueba');

        // Segundo envío de la misma factura: no se crea otra
        $this->actingAs($u)->post('/facturacion/crear', ['mes' => '2026-11', 'sociedades' => ['ACME, S.L.']])->assertSessionHas('error');
        $this->assertSame(1, BorradorFactura::count());
        Http::assertSentCount(2); // contactos (luego en caché) + una sola factura
    }

    public function test_elegir_contacto_cuando_hay_varios_o_ninguno(): void
    {
        $this->holded();
        $u = User::factory()->create(['role' => 'facturacion']);

        // Beta: dos contactos con el mismo nombre, se distinguen por el NIF
        $this->actingAs($u)->get('/facturacion?mes=2026-11')->assertOk()
            ->assertSee('elige cuál')->assertSee('BETA SERVEIS, S.L. · B111')->assertSee('Beta Serveis SL · B222')
            ->assertSee('¿Quisiste decir…?')->assertSee('Gamma Studio SL'); // Gamma sin SL NAME: sugerencia por el cliente

        // Un id que no es de Holded no se guarda
        $this->actingAs($u)->post('/facturacion/contacto', ['mes' => '2026-11', 'sociedad' => 'Beta Serveis SL', 'contacto' => ['Beta Serveis SL' => 'inventado']])
            ->assertSessionHas('error');

        $this->actingAs($u)->post('/facturacion/contacto', ['mes' => '2026-11', 'sociedad' => 'Beta Serveis SL', 'contacto' => ['Beta Serveis SL' => 'c-beta2']])
            ->assertSessionHas('success');
        $this->actingAs($u)->get('/facturacion?mes=2026-11')->assertSee('elegido a mano');

        // Botón individual: solo esa factura, con el contacto elegido
        $this->actingAs($u)->post('/facturacion/crear', ['mes' => '2026-11', 'solo' => 'Beta Serveis SL', 'sociedades' => ['ACME, S.L.', 'Beta Serveis SL']]);
        $this->assertSame(['Beta Serveis SL'], BorradorFactura::pluck('sociedad')->all());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/invoices') && $r['contact_id'] === 'c-beta2');

        // Quitar la elección vuelve a pedir que se elija
        $this->actingAs($u)->post('/facturacion/contacto', ['mes' => '2026-11', 'quitar' => 'Beta Serveis SL']);
        $this->assertSame(0, \App\Facturacion\ContactoFactura::count());
    }

    public function test_sugerencias_de_contactos_parecidos(): void
    {
        $contactos = ['delvy marketing sl' => ['d1' => 'DELVY MARKETING SL'], 'acme sl' => ['a1' => 'Acme SL'], 'otra cosa sa' => ['o1' => 'Otra Cosa SA']];

        $this->assertSame(['d1' => 'DELVY MARKETING SL'], \App\Facturacion\FacturasDelMes::parecidos(['Delvy'], $contactos));
        $this->assertSame([], \App\Facturacion\FacturasDelMes::parecidos(['Febrolaser'], $contactos));
    }

    public function test_buscador_y_no_repite_factura_del_mismo_contacto_en_el_mes(): void
    {
        $this->holded();
        $u = User::factory()->create(['role' => 'facturacion']);

        $this->actingAs($u)->get('/facturacion?mes=2026-11')->assertSee('id="holded-contactos"', false)->assertSee('<option value="Gamma Studio SL">', false);

        // Lo buscado tiene que ser exactamente un contacto de la lista
        $this->actingAs($u)->post('/facturacion/contacto', ['mes' => '2026-11', 'sociedad' => 'Gamma', 'buscar' => ['Gamma' => 'Gamma']])->assertSessionHas('error');
        $this->actingAs($u)->post('/facturacion/contacto', ['mes' => '2026-11', 'sociedad' => 'Gamma', 'buscar' => ['Gamma' => 'Gamma Studio SL'], 'contacto' => ['Gamma' => 'c-acme']]);
        $this->assertSame('c-gamma', \App\Facturacion\ContactoFactura::where('sociedad', 'Gamma')->value('holded_id'));

        $this->actingAs($u)->post('/facturacion/crear', ['mes' => '2026-11', 'solo' => 'Gamma']);
        $this->assertSame('c-gamma', BorradorFactura::sole()->contacto_id);

        // En la hoja se corrige el #N/A de Gamma: misma empresa con otro nombre, y no se ofrece crearla otra vez
        \App\Facturacion\ContactoFactura::create(['sociedad' => 'Gamma Studio, S.L.', 'holded_id' => 'c-gamma']);
        $filas = self::FILAS;
        $filas[7][2] = 'Gamma Studio, S.L.';
        $f = \App\Facturacion\FacturasDelMes::preparar(HojaFacturacion::lineas($filas, 2026, 11), app(Holded::class)->contactosPorNombre(), 2026, 11);
        $this->assertSame(\App\Facturacion\FacturasDelMes::CREADA, $f['Gamma Studio, S.L.']['estado']);

        $this->actingAs($u)->post('/facturacion/crear', ['mes' => '2026-11', 'solo' => 'Gamma'])->assertSessionHas('error');
        $this->assertSame(1, BorradorFactura::count());
    }

    public function test_dos_filas_al_mismo_contacto_solo_crean_un_borrador(): void
    {
        $this->holded();
        $u = User::factory()->create(['role' => 'facturacion']);
        \App\Facturacion\ContactoFactura::create(['sociedad' => 'Beta Serveis SL', 'holded_id' => 'c-acme']);

        $this->actingAs($u)->get('/facturacion?mes=2026-11')->assertSee('va al mismo contacto de Holded');
        $this->actingAs($u)->post('/facturacion/crear', ['mes' => '2026-11', 'sociedades' => ['ACME, S.L.', 'Beta Serveis SL']])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'mismo contacto'));
        $this->assertSame(['ACME, S.L.'], BorradorFactura::pluck('sociedad')->all());
    }

    public function test_se_editan_descripcion_y_unidades_pero_no_concepto_ni_total(): void
    {
        $this->holded();
        $u = User::factory()->create(['role' => 'facturacion']);
        $k = \App\Facturacion\FacturasDelMes::clave('ACME, S.L.');

        $this->actingAs($u)->get('/facturacion?mes=2026-11')
            ->assertSee('name="descripciones['.$k.'][1]"', false)->assertSee('name="unidades['.$k.'][1]"', false)
            ->assertDontSee('name="conceptos[', false);

        // 1.450 € no se reparte exacto en 3: no se crea nada
        $this->actingAs($u)->post('/facturacion/crear', ['mes' => '2026-11', 'solo' => 'ACME, S.L.', 'unidades' => [$k => [1 => 3]]])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'no se reparte exacto'));
        $this->assertSame(0, BorradorFactura::count());

        $this->actingAs($u)->post('/facturacion/crear', [
            'mes' => '2026-11', 'solo' => 'ACME, S.L.',
            'conceptos' => [$k => [1 => 'Otro concepto']], // ya no se acepta
            'descripciones' => [$k => [0 => 'Mantenimiento SEO']],
            'unidades' => [$k => [1 => 2]],
        ]);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/invoices')
            && $r['items'][1]['name'] === 'MRR Paid media' && $r['items'][1]['units'] === 2 && $r['items'][1]['price'] == 725
            && $r['items'][0]['description'] === 'Mantenimiento SEO' && $r['items'][0]['price'] == 800);
        $this->assertSame(2250.0, BorradorFactura::sole()->total);
        $this->actingAs($u)->get('/facturacion?mes=2026-11')->assertSee('2 × 725,00 €');
    }

    public function test_texto_en_el_documento_y_repetirlo_todo_el_anio(): void
    {
        $this->holded();
        $u = User::factory()->create(['role' => 'facturacion']);
        $k = \App\Facturacion\FacturasDelMes::clave('ACME, S.L.');

        $this->actingAs($u)->post('/facturacion/crear', [
            'mes' => '2026-10', 'solo' => 'ACME, S.L.',
            'textos' => [$k => "Orden de compra: PO260199\r\n<b>x</b>"], 'repetir' => [$k => '1'],
        ])->assertSessionMissing('error');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/invoices')
            && $r['body'] === 'Orden de compra: PO260199<br>' . "\n" . '&lt;b&gt;x&lt;/b&gt;');
        $this->assertSame("Orden de compra: PO260199\n<b>x</b>", BorradorFactura::sole()->texto);

        // Noviembre ya lo trae puesto y marcado; solo vale para 2026
        $this->actingAs($u)->get('/facturacion?mes=2026-11')->assertSee('Orden de compra: PO260199')->assertSee('name="repetir['.$k.']" value="1" class="accent-neutral-900" checked', false);
        $this->assertSame([2026], \App\Facturacion\TextoFactura::pluck('anio')->all());

        // Desmarcado: se manda ese mes pero deja de repetirse
        $this->actingAs($u)->post('/facturacion/crear', ['mes' => '2026-11', 'solo' => 'ACME, S.L.', 'textos' => [$k => 'Solo noviembre']]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/invoices') && $r['body'] === 'Solo noviembre');
        $this->assertSame(0, \App\Facturacion\TextoFactura::count());
    }

    public function test_cambiar_contacto_ya_asignado_solo_este_mes_o_siempre(): void
    {
        $this->holded();
        $u = User::factory()->create(['role' => 'facturacion']);
        $C = \App\Facturacion\ContactoFactura::class;

        // Acme sale sola (c-acme) pero se puede cambiar
        $this->actingAs($u)->get('/facturacion?mes=2026-11')->assertSee('Cambiar contacto')->assertSee('name="recordar[ACME, S.L.]"', false);

        // Sin «Recordar siempre»: solo noviembre
        $this->actingAs($u)->post('/facturacion/contacto', ['mes' => '2026-11', 'sociedad' => 'ACME, S.L.', 'buscar' => ['ACME, S.L.' => 'Gamma Studio SL']])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'solo para este mes'));
        $this->actingAs($u)->get('/facturacion?mes=2026-11')->assertSee('solo para este mes');
        $octubre = \App\Facturacion\FacturasDelMes::preparar(HojaFacturacion::lineas(self::FILAS, 2026, 10), app(Holded::class)->contactosPorNombre(), 2026, 10);
        $this->assertSame('c-acme', $octubre['ACME, S.L.']['contacto']); // otro mes sigue con el automático

        // Con «Recordar siempre»: todos los meses, y quita la de este mes
        $this->actingAs($u)->post('/facturacion/contacto', ['mes' => '2026-11', 'sociedad' => 'ACME, S.L.', 'buscar' => ['ACME, S.L.' => 'Beta Serveis SL · B222'], 'recordar' => ['ACME, S.L.' => '1']]);
        $this->assertSame([[null, 'c-beta2']], $C::get()->map(fn ($c) => [$c->mes, $c->holded_id])->all());

        // Una de este mes manda sobre la de siempre; «Quitar elección» quita primero la del mes
        $this->actingAs($u)->post('/facturacion/contacto', ['mes' => '2026-11', 'sociedad' => 'ACME, S.L.', 'buscar' => ['ACME, S.L.' => 'Gamma Studio SL']]);
        $this->actingAs($u)->post('/facturacion/crear', ['mes' => '2026-11', 'solo' => 'ACME, S.L.']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/invoices') && $r['contact_id'] === 'c-gamma');
        $this->actingAs($u)->post('/facturacion/contacto', ['mes' => '2026-11', 'quitar' => 'ACME, S.L.']);
        $this->assertSame([[null, 'c-beta2']], $C::get()->map(fn ($c) => [$c->mes, $c->holded_id])->all());
    }
}
