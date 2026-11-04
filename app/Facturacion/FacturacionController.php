<?php

namespace App\Facturacion;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class FacturacionController extends Controller
{
    public function index(Request $request, HojaFacturacion $hoja, Holded $holded): View
    {
        [$anio, $mes] = $this->mes($request->query('mes'));
        if ($request->boolean('recargar')) {
            Cache::forget('facturacion.holded_contactos');
        }

        $error = null;
        $facturas = [];
        $contactos = null;
        try {
            $lineas = HojaFacturacion::lineas($hoja->filas(), $anio, $mes);
            $contactos = config('facturacion.holded_key') ? $holded->contactosPorNombre() : null;
            $facturas = FacturasDelMes::preparar($lineas, $contactos, $anio, $mes);
            if ($contactos === null) {
                $error = 'Falta HOLDED_API_KEY en el .env: se ve lo que hay que facturar, pero no se pueden crear borradores.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        return view('facturacion.index', [
            'anio' => $anio,
            'mes' => $mes,
            'facturas' => $facturas,
            'contactos' => Holded::etiquetas($contactos ?? []), // para el buscador
            'error' => $error,
        ]);
    }

    public function crear(Request $request, HojaFacturacion $hoja, Holded $holded): RedirectResponse
    {
        $datos = $request->validate([
            'mes' => ['required', 'date_format:Y-m'],
            'sociedades' => ['required_without:solo', 'array'],
            'sociedades.*' => ['string'],
            'solo' => ['nullable', 'string'], // botón «Crear» de una sola factura
            'conceptos' => ['nullable', 'array'],
            'conceptos.*' => ['array'],
            'conceptos.*.*' => ['nullable', 'string', 'max:255'],
            'descripciones' => ['nullable', 'array'],
            'descripciones.*' => ['array'],
            'descripciones.*.*' => ['nullable', 'string', 'max:255'],
        ], ['sociedades.required_without' => 'Marca al menos una factura.']);
        $sociedades = isset($datos['solo']) ? [$datos['solo']] : $datos['sociedades'];
        [$anio, $mes] = $this->mes($datos['mes']);
        $volver = redirect()->route('facturacion.index', ['mes' => $datos['mes']])->withInput();

        // Un solo envío a la vez por mes: dos clics seguidos no crean dos facturas
        $cerrojo = Cache::lock('facturacion.crear.' . $datos['mes'], 300);
        if (! $cerrojo->get()) {
            return $volver->with('error', 'Ya se están creando borradores de este mes. Espera un momento y recarga.');
        }

        $creadas = 0;
        $fallos = [];
        try {
            // Se vuelve a leer la hoja: los importes salen de ella, nunca del formulario
            $facturas = FacturasDelMes::preparar(HojaFacturacion::lineas($hoja->filas(), $anio, $mes), $holded->contactosPorNombre(), $anio, $mes);
            $usados = []; // contactos ya facturados en este envío
            foreach (array_unique($sociedades) as $sociedad) {
                $f = $facturas[$sociedad] ?? null;
                if (! $f || $f['estado'] !== FacturasDelMes::LISTA) {
                    $fallos[] = "{$sociedad}: ya no está lista para crear.";
                    continue;
                }
                if (isset($usados[$f['contacto']])) {
                    $fallos[] = "{$sociedad}: ya se ha creado en este envío un borrador para el mismo contacto de Holded.";
                    continue;
                }
                // Solo el texto de las líneas puede venir del formulario; vacío = el de la hoja
                $k = FacturasDelMes::clave($sociedad);
                foreach ($f['lineas'] as $i => &$l) {
                    $l['concepto'] = trim($datos['conceptos'][$k][$i] ?? '') ?: $l['concepto'];
                    $l['descripcion'] = trim($datos['descripciones'][$k][$i] ?? '') ?: $l['descripcion'];
                }
                unset($l);
                try {
                    $id = $holded->crearBorrador($f['contacto'], now('Europe/Madrid')->toDateString(), $f['lineas']);
                    BorradorFactura::create([
                        'mes' => $datos['mes'],
                        'sociedad' => $sociedad,
                        'contacto_id' => $f['contacto'],
                        'holded_id' => $id,
                        'total' => $f['total'],
                        'lineas' => $f['lineas'],
                        'user_id' => $request->user()->id,
                    ]);
                    $usados[$f['contacto']] = true;
                    $creadas++;
                } catch (Throwable $e) {
                    Log::warning('Facturación: no se pudo crear el borrador', ['sociedad' => $sociedad, 'error' => $e->getMessage()]);
                    $fallos[] = "{$sociedad}: {$e->getMessage()}";
                }
            }
        } catch (Throwable $e) {
            $fallos[] = $e->getMessage();
        } finally {
            $cerrojo->release();
        }

        $volver->with('success', $creadas === 1 ? 'Se ha creado 1 borrador en Holded.' : "Se han creado {$creadas} borradores en Holded.");

        return $fallos ? $volver->with('error', implode("\n", $fallos)) : $volver;
    }

    /** Guarda (o quita) el contacto de Holded elegido a mano para una sociedad; vale para todos los meses. */
    public function contacto(Request $request, Holded $holded): RedirectResponse
    {
        $datos = $request->validate([
            'mes' => ['required', 'date_format:Y-m'],
            'sociedad' => ['required_without:quitar', 'string'],
            'quitar' => ['nullable', 'string'],
            'contacto' => ['nullable', 'array'],
            'buscar' => ['nullable', 'array'],
        ]);
        $volver = redirect()->route('facturacion.index', ['mes' => $datos['mes']])->withInput(); // no se pierden los textos editados

        if (isset($datos['quitar'])) {
            ContactoFactura::where('sociedad', $datos['quitar'])->delete();

            return $volver;
        }
        $sociedad = $datos['sociedad'];
        $etiquetas = Holded::etiquetas($holded->contactosPorNombre());
        // Lo escrito en el buscador manda sobre las sugerencias; tiene que ser un contacto de la lista
        $buscado = trim((string) ($datos['buscar'][$sociedad] ?? ''));
        if ($buscado !== '') {
            $ids = array_keys($etiquetas, $buscado, true);
            if (count($ids) !== 1) {
                return $volver->with('error', $ids ? "{$sociedad}: hay varios contactos «{$buscado}» en Holded; elige uno de la lista." : "{$sociedad}: no hay ningún contacto «{$buscado}» en Holded; elígelo de la lista que sale al escribir.");
            }
            $id = $ids[0];
        } else {
            $id = $datos['contacto'][$sociedad] ?? null;
        }
        // El id viene del formulario: solo se acepta si es un contacto que existe en Holded
        if (! $id || ! isset($etiquetas[$id])) {
            return $volver->with('error', "{$sociedad}: elige uno de los contactos.");
        }
        ContactoFactura::updateOrCreate(['sociedad' => $sociedad], ['holded_id' => $id]);

        return $volver->with('success', "{$sociedad}: contacto guardado. Se usará también los próximos meses.");
    }

    /** «2026-11» → [2026, 11]; sin mes, el actual. */
    private function mes(?string $mes): array
    {
        if ($mes && preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $mes, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }
        $hoy = now('Europe/Madrid');

        return [$hoy->year, $hoy->month];
    }
}
