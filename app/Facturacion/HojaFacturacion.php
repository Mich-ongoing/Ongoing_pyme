<?php

namespace App\Facturacion;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * La hoja «BBDD KPIs Analysis»: una fila por cliente + sociedad + línea de ingreso, y una columna por mes.
 * Solo se lee (Apps Script con permiso de solo lectura): la hoja la usan el contable y otras aplicaciones.
 */
class HojaFacturacion
{
    /** Filas de la hoja tal cual (columna A = índice 0), desde el Apps Script de solo lectura. */
    public function filas(): array
    {
        $url = config('facturacion.apps_script_url');
        $token = config('facturacion.apps_script_token');
        if (! $url || ! $token) {
            throw new RuntimeException('Faltan FACTURACION_APPS_SCRIPT_URL y FACTURACION_APPS_SCRIPT_TOKEN en el .env.');
        }
        // Apps Script responde con una redirección a googleusercontent.com, que Http sigue sola
        $r = Http::timeout(60)->get($url, ['token' => $token]);
        if (! $r->successful() || ! is_array($r->json('values'))) {
            throw new RuntimeException('El Apps Script de la hoja no respondió bien: ' . ($r->json('error') ?? 'HTTP ' . $r->status()));
        }

        return $r->json('values');
    }

    /**
     * Líneas con importe en ese mes, con el importe del mes anterior para avisar de cambios.
     * Encuentra las columnas por las cabeceras (año encima, mes debajo), no por posición.
     *
     * @return list<array{cliente: string, sociedad: string, linea: string, importe: float, anterior: float}>
     */
    public static function lineas(array $filas, int $anio, int $mes): array
    {
        $cab = null;
        foreach ($filas as $i => $f) {
            if (trim((string) ($f[1] ?? '')) === 'CLIENT NAME') {
                $cab = $i;
                break;
            }
        }
        if ($cab === null || $cab === 0) {
            throw new RuntimeException('No encuentro la fila de cabecera «CLIENT NAME» en la hoja.');
        }
        $col = self::columna($filas[$cab - 1], $filas[$cab], $anio, $mes);
        if ($col === null) {
            throw new RuntimeException(sprintf('La hoja no tiene la columna de %02d/%d.', $mes, $anio));
        }
        $antes = $mes === 1 ? self::columna($filas[$cab - 1], $filas[$cab], $anio - 1, 12) : self::columna($filas[$cab - 1], $filas[$cab], $anio, $mes - 1);

        $ignorar = config('facturacion.ignorar', []);
        $contiene = config('facturacion.ignorar_si_contiene', []);
        $lineas = [];
        for ($i = $cab + 1; $i < count($filas); $i++) {
            $f = $filas[$i];
            if (trim((string) ($f[1] ?? '')) === 'TOTAL REVENUE') {
                break; // debajo empieza el bloque de horas
            }
            $linea = trim((string) ($f[3] ?? ''));
            $importe = self::numero($f[$col] ?? '');
            if ($importe <= 0 || in_array($linea, $ignorar, true) || \Illuminate\Support\Str::contains($linea, $contiene, true)) {
                continue;
            }
            $lineas[] = [
                'cliente' => trim((string) ($f[1] ?? '')),
                'sociedad' => trim(preg_replace('/\s+/', ' ', (string) ($f[2] ?? ''))),
                'linea' => $linea,
                'importe' => $importe,
                'anterior' => $antes === null ? 0.0 : self::numero($f[$antes] ?? ''),
            ];
        }

        return $lineas;
    }

    private static function columna(array $anios, array $meses, int $anio, int $mes): ?int
    {
        foreach ($meses as $c => $m) {
            if ((string) $m !== '' && self::numero($m) == $mes && self::numero($anios[$c] ?? '') == $anio) {
                return $c;
            }
        }

        return null;
    }

    /** 1200 / "1.200" / "1.200,50" / "2.025" → número (formato español de la exportación o valor sin formato de la API). */
    public static function numero(mixed $v): float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $v = str_replace([' ', '€', "\u{a0}"], '', (string) $v);
        if (str_contains($v, ',')) {
            $v = str_replace(['.', ','], ['', '.'], $v);
        } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $v)) {
            $v = str_replace('.', '', $v);
        }

        return is_numeric($v) ? (float) $v : 0.0;
    }
}
