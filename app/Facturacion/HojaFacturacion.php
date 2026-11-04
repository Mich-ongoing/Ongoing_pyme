<?php

namespace App\Facturacion;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * La hoja «BBDD KPIs Analysis»: una fila por cliente + sociedad + línea de ingreso, y una columna por mes.
 * Solo se lee (con una cuenta de servicio en modo «Lector»): la hoja la usan el contable y otras aplicaciones.
 */
class HojaFacturacion
{
    /** Filas de la hoja tal cual (columna A = índice 0), desde Google Sheets. */
    public function filas(): array
    {
        $id = config('facturacion.hoja_id');
        if (! $id) {
            throw new RuntimeException('Falta FACTURACION_HOJA_ID en el .env.');
        }
        $r = Http::withToken($this->token())->timeout(30)
            ->get('https://sheets.googleapis.com/v4/spreadsheets/' . $id . '/values/' . rawurlencode(config('facturacion.hoja_rango')), [
                'valueRenderOption' => 'UNFORMATTED_VALUE',
            ]);
        if (! $r->successful()) {
            throw new RuntimeException('Google Sheets respondió ' . $r->status() . ': ' . $r->json('error.message', $r->body()));
        }

        return $r->json('values', []);
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
        $lineas = [];
        for ($i = $cab + 1; $i < count($filas); $i++) {
            $f = $filas[$i];
            if (trim((string) ($f[1] ?? '')) === 'TOTAL REVENUE') {
                break; // debajo empieza el bloque de horas
            }
            $linea = trim((string) ($f[3] ?? ''));
            $importe = self::numero($f[$col] ?? '');
            if ($importe <= 0 || in_array($linea, $ignorar, true)) {
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

    /** Token OAuth de la cuenta de servicio (JWT firmado con su clave privada), 50 minutos en caché. */
    private function token(): string
    {
        return Cache::remember('facturacion.google_token', 3000, function () {
            $ruta = config('facturacion.google_credenciales');
            $cred = is_file($ruta) ? json_decode((string) file_get_contents($ruta), true) : null;
            if (! isset($cred['client_email'], $cred['private_key'])) {
                throw new RuntimeException('Faltan las credenciales de la cuenta de servicio de Google en ' . $ruta . '.');
            }
            $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $ahora = time();
            $sin_firma = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $b64(json_encode([
                'iss' => $cred['client_email'],
                'scope' => 'https://www.googleapis.com/auth/spreadsheets.readonly',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $ahora,
                'exp' => $ahora + 3600,
            ]));
            openssl_sign($sin_firma, $firma, $cred['private_key'], OPENSSL_ALGO_SHA256);
            $r = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $sin_firma . '.' . $b64($firma),
            ]);
            if (! $r->successful()) {
                throw new RuntimeException('Google no dio acceso a la hoja: ' . $r->json('error_description', $r->body()));
            }

            return $r->json('access_token');
        });
    }
}
