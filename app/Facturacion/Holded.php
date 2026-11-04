<?php

namespace App\Facturacion;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/** API v2 de Holded: contactos y facturas. Una factura creada por la API queda en borrador hasta que se aprueba en Holded. */
class Holded
{
    private const API = 'https://api.holded.com/api/v2';

    private function http(): PendingRequest
    {
        $clave = config('facturacion.holded_key');
        if (! $clave) {
            throw new RuntimeException('Falta HOLDED_API_KEY en el .env.');
        }

        return Http::withToken($clave)->acceptJson()->timeout(30)->retry(2, 1000, throw: false);
    }

    /**
     * Contactos por nombre normalizado (nombre fiscal y nombre comercial): [nombre => [id => «Nombre · NIF»]].
     * Varios con el mismo nombre = ambiguo (lista con más de uno).
     */
    public function contactosPorNombre(): array
    {
        return Cache::remember('facturacion.holded_contactos', 600, function () {
            $porNombre = [];
            $cursor = null;
            do {
                $r = $this->http()->get(self::API . '/contacts', array_filter(['limit' => 100, 'cursor' => $cursor]));
                if (! $r->successful()) {
                    throw new RuntimeException('Holded respondió ' . $r->status() . ' al pedir los contactos: ' . $r->body());
                }
                foreach ($r->json('items', []) as $c) {
                    foreach (array_unique(array_filter([self::normalizar($c['name'] ?? ''), self::normalizar($c['trade_name'] ?? '')])) as $n) {
                        $porNombre[$n][$c['id']] = implode(' · ', array_filter([$c['name'] ?? '', $c['code'] ?? '']));
                    }
                }
                $cursor = $r->json('cursor');
            } while ($r->json('has_more') && $cursor);

            return $porNombre;
        });
    }

    /** De la salida de contactosPorNombre() a [id => «Nombre · NIF»], un contacto una vez. */
    public static function etiquetas(array $contactos): array
    {
        return array_replace([], ...array_values($contactos));
    }

    /** Crea la factura sin aprobar (borrador) y devuelve su id. El importe de cada línea es su total: precio = importe / unidades. */
    public function crearBorrador(string $contactoId, string $fecha, array $lineas): string
    {
        $r = $this->http()->post(self::API . '/invoices', [
            'contact_id' => $contactoId,
            'date' => $fecha,
            'items' => array_map(fn ($l) => [
                'name' => $l['concepto'],
                'description' => $l['descripcion'],
                'type' => 'service',
                'units' => $l['unidades'] ?? 1,
                'price' => round($l['importe'] / ($l['unidades'] ?? 1), 2),
                'taxes' => [config('facturacion.iva')],
            ], $lineas),
        ]);
        if (! $r->successful() || ! $r->json('id')) {
            throw new RuntimeException('Holded respondió ' . $r->status() . ': ' . Str::limit($r->body(), 300));
        }

        return $r->json('id');
    }

    /** «Pixel Comunicació, S.L.» y «PIXEL COMUNICACIO SL» → «pixel comunicacio sl». */
    public static function normalizar(string $nombre): string
    {
        $n = Str::lower(Str::ascii($nombre));
        $n = preg_replace('/[^a-z0-9]+/', ' ', $n);
        $n = preg_replace('/\b(s) (l|a|c|coop|l u|a u)\b/', 's$2', $n); // s l → sl, s a → sa
        $n = str_replace(['sl u', 'sa u'], ['slu', 'sau'], $n);

        return trim(preg_replace('/\s+/', ' ', $n));
    }
}
