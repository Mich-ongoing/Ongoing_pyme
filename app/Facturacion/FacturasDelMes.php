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
        $creadas = BorradorFactura::where('mes', $clave)->get();

        $elegidos = ContactoFactura::pluck('holded_id', 'sociedad');
        $etiquetas = Holded::etiquetas($contactos ?? []);

        $facturas = [];
        foreach ($lineas as $l) {
            // Sin SL NAME (vacío o #N/A del BUSCARV) se agrupa por cliente para no juntar clientes distintos
            $sinSociedad = $l['sociedad'] === '' || str_starts_with($l['sociedad'], '#');
            $f = &$facturas[$sinSociedad ? $l['cliente'] : $l['sociedad']];
            $f['sociedad'] ??= $sinSociedad ? $l['cliente'] : $l['sociedad'];
            if ($sinSociedad) {
                $f['avisos'][] = 'La hoja no tiene sociedad (SL NAME): se busca por el nombre del cliente.';
            }
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
            [$f['contacto'], $f['contacto_nombre'], $f['manual'], $f['opciones']] = [null, null, false, []];
            $f['estado'] = self::SIN_CONTACTO;
            // Ya hecha este mes: por la sociedad o, si en la hoja cambió el nombre, por el contacto de Holded
            $f['borrador'] = $creadas->firstWhere('sociedad', $sociedad);
            if (! $f['borrador'] && $contactos !== null) {
                self::contacto($f, $contactos, $etiquetas, $elegidos[$sociedad] ?? null);
                $f['borrador'] = $f['contacto'] ? $creadas->firstWhere('contacto_id', $f['contacto']) : null;
                if ($f['borrador']) {
                    $f['avisos'][] = "Este mes ya hay un borrador para este contacto de Holded (se creó como «{$f['borrador']->sociedad}»).";
                }
            }
            if ($f['borrador']) {
                $f['estado'] = self::CREADA;
                $f['opciones'] = [];
                if (abs($f['borrador']->total - $f['total']) >= 0.01) {
                    $f['avisos'][] = sprintf('La hoja ha cambiado desde que se creó el borrador: %s → %s €. Corrígelo en Holded.', self::euros($f['borrador']->total), self::euros($f['total']));
                }
            }
        }
        unset($f);

        // Dos filas de la hoja hacia el mismo contacto: se avisa, y al crear solo sale la primera
        $porContacto = array_count_values(array_filter(array_map(fn ($f) => $f['estado'] === self::LISTA ? $f['contacto'] : null, $facturas)));
        foreach ($facturas as &$f) {
            if ($f['estado'] === self::LISTA && $porContacto[$f['contacto']] > 1) {
                $f['avisos'][] = 'Otra factura de este mes va al mismo contacto de Holded: solo se creará una.';
            }
        }
        unset($f);

        uasort($facturas, fn ($a, $b) => [$a['estado'] !== self::SIN_CONTACTO, $a['sociedad']] <=> [$b['estado'] !== self::SIN_CONTACTO, $b['sociedad']]);

        return $facturas;
    }

    /** Busca el contacto de Holded: el elegido a mano, el único con ese nombre, o deja opciones para elegir. */
    private static function contacto(array &$f, array $contactos, array $etiquetas, ?string $elegido): void
    {
        if ($elegido && isset($etiquetas[$elegido])) {
            [$f['contacto'], $f['contacto_nombre'], $f['manual'], $f['estado']] = [$elegido, $etiquetas[$elegido], true, self::LISTA];

            return;
        }
        $encontrados = $contactos[Holded::normalizar($f['sociedad'])] ?? [];
        if (count($encontrados) === 1) {
            [$f['contacto'], $f['contacto_nombre'], $f['estado']] = [array_key_first($encontrados), reset($encontrados), self::LISTA];
        } elseif ($encontrados) {
            $f['avisos'][] = 'Hay varios contactos con este nombre en Holded: elige cuál.';
            $f['opciones'] = $encontrados;
        } else {
            $f['opciones'] = self::parecidos(array_merge([$f['sociedad']], $f['clientes']), $contactos);
            $f['avisos'][] = $f['opciones'] ? 'No hay ningún contacto con este nombre en Holded. ¿Quisiste decir…?' : 'No hay ningún contacto con este nombre ni parecido en Holded: búscalo abajo o créalo allí y pulsa Actualizar.';
        }
        if ($elegido) {
            $f['avisos'][] = 'El contacto elegido antes ya no existe en Holded.';
        }
    }

    /** Hasta 5 contactos de Holded con nombre parecido a alguno de los buscados: [id => etiqueta], el más parecido primero. */
    public static function parecidos(array $buscados, array $contactos): array
    {
        $sinForma = fn ($n) => trim(preg_replace('/\b(sl|slu|slp|sa|sau|scp|cb)\b/', '', Holded::normalizar($n)));
        $buscados = array_filter(array_map($sinForma, $buscados), fn ($b) => strlen($b) >= 3);
        $puntos = [];
        $etiquetas = [];
        foreach ($contactos as $nombre => $ids) {
            $n = $sinForma($nombre);
            $mejor = 0;
            foreach ($buscados as $b) {
                similar_text($b, $n, $pct);
                if (strlen($n) >= 4 && (str_contains($n, $b) || str_contains($b, $n))) {
                    $pct = max($pct, 85);
                }
                $mejor = max($mejor, $pct);
            }
            if ($mejor >= 60) {
                foreach ($ids as $id => $etiqueta) {
                    $puntos[$id] = max($puntos[$id] ?? 0, $mejor);
                    $etiquetas[$id] = $etiqueta;
                }
            }
        }
        arsort($puntos);

        $ids = array_keys(array_slice($puntos, 0, 5, true));

        return array_combine($ids, array_map(fn ($id) => $etiquetas[$id], $ids));
    }

    public static function euros(float $n): string
    {
        return number_format($n, 2, ',', '.');
    }
}
