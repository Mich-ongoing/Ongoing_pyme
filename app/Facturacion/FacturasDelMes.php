<?php

namespace App\Facturacion;

use Carbon\CarbonImmutable;

/** Agrupa las líneas de la hoja en una factura por sociedad y le pone estado y avisos antes de crear nada. */
class FacturasDelMes
{
    public const LISTA = 'lista';
    public const CREADA = 'creada';
    public const SIN_CONTACTO = 'sin_contacto';

    /**
     * @param  array  $lineas  salida de HojaFacturacion::lineas()
     * @param  array|null  $contactos  salida de Holded::contactosPorNombre(); null = Holded sin configurar
     */
    public static function preparar(array $lineas, ?array $contactos, int $anio, int $mes): array
    {
        $clave = sprintf('%d-%02d', $anio, $mes);
        $nombreMes = ucfirst(CarbonImmutable::create($anio, $mes, 1)->locale('es')->translatedFormat('F Y'));
        $conceptos = config('facturacion.conceptos', []);
        $creadas = BorradorFactura::where('mes', $clave)->get()->keyBy('sociedad');

        $facturas = [];
        foreach ($lineas as $l) {
            $f = &$facturas[$l['sociedad']];
            $f['sociedad'] ??= $l['sociedad'];
            $f['clientes'][$l['cliente']] = true;
            $f['avisos'] ??= [];
            $f['lineas'][] = [
                'concepto' => $conceptos[$l['linea']] ?? $l['linea'],
                'descripcion' => $nombreMes,
                'linea' => $l['linea'],
                'importe' => round($l['importe'], 2),
            ];
            if (! isset($conceptos[$l['linea']])) {
                $f['avisos'][] = "«{$l['linea']}» no tiene concepto definido: sale tal cual.";
            }
            if (str_contains($l['linea'], 'Kids Digitales')) {
                $f['avisos'][] = 'Kit Digital: revisa en el borrador el texto obligatorio de la subvención.';
            }
            if ($l['anterior'] > 0 && abs($l['anterior'] - $l['importe']) >= 0.01) {
                $f['avisos'][] = sprintf('%s cambia respecto al mes anterior: %s → %s €.', $l['linea'], self::euros($l['anterior']), self::euros($l['importe']));
            }
            unset($f);
        }

        foreach ($facturas as $sociedad => &$f) {
            $f['clientes'] = array_keys($f['clientes']);
            $f['avisos'] = array_values(array_unique($f['avisos']));
            $f['total'] = round(array_sum(array_column($f['lineas'], 'importe')), 2);
            $f['contacto'] = null;
            $f['borrador'] = $creadas[$sociedad] ?? null;

            if ($f['borrador']) {
                $f['estado'] = self::CREADA;
                if (abs($f['borrador']->total - $f['total']) >= 0.01) {
                    $f['avisos'][] = sprintf('La hoja ha cambiado desde que se creó el borrador: %s → %s €. Corrígelo en Holded.', self::euros($f['borrador']->total), self::euros($f['total']));
                }
                continue;
            }
            $f['estado'] = self::SIN_CONTACTO;
            if ($sociedad === '' || $sociedad === '#N/A') {
                $f['avisos'][] = 'La hoja no tiene sociedad (SL NAME) para este cliente.';
            } elseif ($contactos !== null) {
                $encontrados = $contactos[Holded::normalizar($sociedad)] ?? [];
                if (count($encontrados) === 1) {
                    $f['contacto'] = array_key_first($encontrados);
                    $f['estado'] = self::LISTA;
                } else {
                    $f['avisos'][] = $encontrados ? 'Hay varios contactos con este nombre en Holded.' : 'No hay ningún contacto con este nombre en Holded.';
                }
            }
        }
        unset($f);

        uasort($facturas, fn ($a, $b) => [$a['estado'] !== self::SIN_CONTACTO, $a['sociedad']] <=> [$b['estado'] !== self::SIN_CONTACTO, $b['sociedad']]);

        return $facturas;
    }

    public static function euros(float $n): string
    {
        return number_format($n, 2, ',', '.');
    }
}
