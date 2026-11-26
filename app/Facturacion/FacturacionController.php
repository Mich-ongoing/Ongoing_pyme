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
            $lineas = HojaFacturacion::lineas($hoja->filas(), $anio, $mes, $hoja->comentarios);
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
            'unidades' => ['nullable', 'array'],
            'unidades.*' => ['array'],
            'unidades.*.*' => ['nullable', 'integer', 'min:1', 'max:999'],
            'descripciones' => ['nullable', 'array'],
            'descripciones.*' => ['array'],
            'descripciones.*.*' => ['nullable', 'string', 'max:255'],
            'textos' => ['nullable', 'array'],
            'textos.*' => ['nullable', 'string', 'max:2000'],
            'repetir' => ['nullable', 'array'],
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
            $facturas = FacturasDelMes::preparar(HojaFacturacion::lineas($hoja->filas(), $anio, $mes, $hoja->comentarios), $holded->contactosPorNombre(), $anio, $mes);
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
                // Del formulario solo vienen descripción y unidades; el total de cada línea es siempre el de la hoja
                $k = FacturasDelMes::clave($sociedad);
                $reparto = null;
                foreach ($f['lineas'] as $i => &$l) {
                    if (array_key_exists($i, $datos['descripciones'][$k] ?? [])) {
                        $l['descripcion'] = trim((string) $datos['descripciones'][$k][$i]);
                    }
                    $l['unidades'] = (int) ($datos['unidades'][$k][$i] ?? 1) ?: 1;
                    if (abs(round($l['importe'] / $l['unidades'], 2) * $l['unidades'] - $l['importe']) >= 0.005) {
                        $reparto = sprintf('%s: %s € no se reparte exacto en %d unidades.', $l['concepto'], FacturasDelMes::euros($l['importe']), $l['unidades']);
                    }
                }
                unset($l);
                if ($reparto) {
                    $fallos[] = "{$sociedad}: {$reparto}";
                    continue;
                }
                // Sin el campo en el envío se usa el guardado para el año (vacío llega como null: no vale ??)
                $conTexto = array_key_exists($k, $datos['textos'] ?? []);
                $texto = trim(str_replace("\r\n", "\n", (string) ($conTexto ? $datos['textos'][$k] : $f['texto'])));
                try {
                    $id = $holded->crearBorrador($f['contacto'], now('Europe/Madrid')->toDateString(), $f['lineas'], $texto);
                    BorradorFactura::create([
                        'mes' => $datos['mes'],
                        'sociedad' => $sociedad,
                        'contacto_id' => $f['contacto'],
                        'holded_id' => $id,
                        'total' => $f['total'],
                        'lineas' => $f['lineas'],
                        'texto' => $texto !== '' ? $texto : null,
                        'user_id' => $request->user()->id,
                    ]);
                    $usados[$f['contacto']] = true;
                    // «Repetir todo el año» marcado: se guarda para los demás meses de ese año; desmarcado, deja de repetirse
                    if ($conTexto && ! empty($datos['repetir'][$k]) && $texto !== '') {
                        TextoFactura::updateOrCreate(['sociedad' => $sociedad, 'anio' => $anio], ['texto' => $texto]);
                    } elseif ($conTexto) {
                        TextoFactura::where(['sociedad' => $sociedad, 'anio' => $anio])->delete();
                    }
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

    /** Guarda (o quita) el contacto de Holded elegido a mano para una sociedad: para siempre con «Recordar siempre», si no solo ese mes. */
    public function contacto(Request $request, Holded $holded): RedirectResponse
    {
        $datos = $request->validate([
            'mes' => ['required', 'date_format:Y-m'],
            'sociedad' => ['required_without:quitar', 'string'],
            'quitar' => ['nullable', 'string'],
            'contacto' => ['nullable', 'array'],
            'buscar' => ['nullable', 'array'],
            'recordar' => ['nullable', 'array'],
        ]);
        $volver = redirect()->route('facturacion.index', ['mes' => $datos['mes']])->withInput(); // no se pierden los textos editados

        if (isset($datos['quitar'])) {
            // Primero la elección de este mes; si no hay, la de siempre
            ContactoFactura::where('sociedad', $datos['quitar'])->where(fn ($q) => $q->whereNull('mes')->orWhere('mes', $datos['mes']))
                ->orderByRaw('mes is null')->first()?->delete();

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
        if (! empty($datos['recordar'][$sociedad])) {
            ContactoFactura::where(['sociedad' => $sociedad, 'mes' => $datos['mes']])->delete();
            ContactoFactura::updateOrCreate(['sociedad' => $sociedad, 'mes' => null], ['holded_id' => $id]);

            return $volver->with('success', "{$sociedad}: contacto guardado. Se usará también los próximos meses.");
        }
        ContactoFactura::updateOrCreate(['sociedad' => $sociedad, 'mes' => $datos['mes']], ['holded_id' => $id]);

        return $volver->with('success', "{$sociedad}: contacto cambiado solo para este mes.");
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
