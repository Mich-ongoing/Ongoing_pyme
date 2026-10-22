<?php

namespace App\Webminder\Panel\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Comparación visual antes/después de una actualización con Cloudflare Browser
 * Run (Chrome sin pantalla en Cloudflare, API REST /screenshot).
 * Sin WEBMINDER_CF_ACCOUNT_ID y WEBMINDER_CF_TOKEN la función queda apagada.
 */
class CapturasService
{
    public const VISTAS = [
        'escritorio' => ['width' => 1366, 'height' => 900],
        'movil' => ['width' => 390, 'height' => 844],
    ];

    // Algunas webs (y cachés como LiteSpeed) sirven otro HTML a móviles
    private const UA_MOVIL = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

    /** % de la página que puede cambiar sin avisar. */
    public const UMBRAL = 3.0;

    // Se compara a 1/4 de resolución: menos memoria y no salta por antialiasing
    private const ESCALA = 4;

    // Suma de diferencias RGB a partir de la cual un píxel cuenta como distinto
    private const TOLERANCIA = 60;

    public function activa(): bool
    {
        return config('services.capturas.cuenta') && config('services.capturas.token') && !Cache::has('watchwp-capturas-agotadas');
    }

    /**
     * Captura de página completa en cada vista. De una en una: el plan gratuito
     * de Cloudflare admite 1 cada 10 s y responde 429 con Retry-After si se pasa.
     * @return array<string,?string> vista => jpeg, o null si esa captura falló
     */
    public function capturar(string $url): array
    {
        // Parámetro propio: que ninguna caché de página sirva la versión de antes
        $url .= (str_contains($url, '?') ? '&' : '?') . 'webminder_check=' . Str::random(8);
        $endpoint = 'https://api.cloudflare.com/client/v4/accounts/' . config('services.capturas.cuenta') . '/browser-run/screenshot';

        $capturas = [];
        foreach (self::VISTAS as $vista => $viewport) {
            $capturas[$vista] = null;
            for ($intento = 0; $intento < 4; $intento++) {
                try {
                    $r = Http::withToken((string) config('services.capturas.token'))->timeout(90)->post($endpoint, array_filter([
                        'url' => $url,
                        'viewport' => $viewport,
                        'userAgent' => $vista === 'movil' ? self::UA_MOVIL : null,
                        'screenshotOptions' => ['type' => 'jpeg', 'quality' => 70, 'fullPage' => true],
                        'gotoOptions' => ['waitUntil' => 'networkidle2', 'timeout' => 45000],
                        // Animaciones y cursores parpadeantes darían diferencias falsas
                        'addStyleTag' => [['content' => '*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}']],
                    ]));
                } catch (ConnectionException) {
                    continue;
                }
                if ($r->status() === 429) {
                    // Cuota diaria gastada (Retry-After de horas): sin capturas hasta que vuelva, en vez de esperar en cada plugin
                    if ((int) $r->header('Retry-After') > 60) {
                        Cache::put('watchwp-capturas-agotadas', true, now()->addSeconds((int) $r->header('Retry-After')));

                        return array_fill_keys(array_keys(self::VISTAS), null);
                    }
                    Sleep::for(min(60, max(10, (int) $r->header('Retry-After'))))->seconds();
                    continue;
                }
                $capturas[$vista] = $r->successful() && str_starts_with($r->body(), "\xFF\xD8") ? $r->body() : null;
                break;
            }
        }

        return $capturas;
    }

    /**
     * Compara antes y después, ignorando lo que ya cambiaba entre dos capturas de
     * antes (sliders, fechas, banners rotativos). Lo que crece o encoge la página
     * cuenta entero como cambio.
     * @return array{0: float, 1: string} porcentaje cambiado y jpeg con los cambios en rojo
     */
    public function comparar(string $antes, string $antes2, string $despues): array
    {
        @ini_set('memory_limit', '1024M'); // páginas largas: hasta ~16.000 px de alto
        [$a, $b, $c] = array_map(fn ($jpg) => $this->reducir($jpg), [$antes, $antes2, $despues]);
        $ancho = min(imagesx($a), imagesx($b), imagesx($c));
        $comun = min(imagesy($a), imagesy($b), imagesy($c));
        $extra = (max(imagesy($a), imagesy($c)) - min(imagesy($a), imagesy($c))) * $ancho;

        $rojo = imagecolorallocatealpha($c, 255, 0, 0, 50);
        $distintos = $contados = 0;
        for ($y = 0; $y < $comun; $y++) {
            for ($x = 0; $x < $ancho; $x++) {
                $pa = imagecolorat($a, $x, $y);
                if (self::distinto($pa, imagecolorat($b, $x, $y))) {
                    continue; // ruido: ya cambiaba sin actualizar nada
                }
                $contados++;
                if (self::distinto($pa, imagecolorat($c, $x, $y))) {
                    $distintos++;
                    imagesetpixel($c, $x, $y, $rojo);
                }
            }
        }
        if (imagesy($c) > $comun) {
            imagefilledrectangle($c, 0, $comun, imagesx($c) - 1, imagesy($c) - 1, $rojo);
        }
        $porcentaje = round(($distintos + $extra) / max(1, $contados + $extra) * 100, 2);

        ob_start();
        imagejpeg($c, null, 70);

        return [$porcentaje, ob_get_clean()];
    }

    /**
     * Guarda las capturas de un ítem y su comparación. Con varias capturas de después
     * (se repite cuando la primera sale distinta) cuenta la más parecida a antes: un fallo
     * pasajero, como una hoja de estilos que no llegó, no es una web rota.
     * @param array<string,?string> $antes
     * @param array<string,?string> $antes2
     * @param array<string,?string> ...$despues
     * @return array<string,?float> vista => % cambiado (null si faltó alguna captura)
     */
    public function compararYGuardar(int $ejecucionId, int $i, array $antes, array $antes2, array ...$despues): array
    {
        $resultado = [];
        foreach (array_keys(self::VISTAS) as $vista) {
            $candidatas = array_filter(array_column($despues, $vista));
            if (!$antes[$vista] || !$antes2[$vista] || !$candidatas) {
                $resultado[$vista] = null;
                continue;
            }
            $mejor = null;
            foreach ($candidatas as $jpg) {
                [$porcentaje, $diff] = $this->comparar($antes[$vista], $antes2[$vista], $jpg);
                if (!$mejor || $porcentaje < $mejor[0]) {
                    $mejor = [$porcentaje, $diff, $jpg];
                }
            }
            [$porcentaje, $diff, $elegida] = $mejor;
            foreach (['antes' => $antes[$vista], 'despues' => $elegida, 'diff' => $diff] as $tipo => $jpg) {
                Storage::disk('local')->put(self::ruta($ejecucionId, "{$i}-{$vista}-{$tipo}.jpg"), $jpg);
            }
            $resultado[$vista] = $porcentaje;
        }

        return $resultado;
    }

    public static function ruta(int $ejecucionId, string $archivo = ''): string
    {
        return "capturas/{$ejecucionId}" . ($archivo !== '' ? '/' . $archivo : '');
    }

    private function reducir(string $jpg): \GdImage
    {
        $img = imagecreatefromstring($jpg);
        $reducida = imagescale($img, max(1, intdiv(imagesx($img), self::ESCALA)));
        imagedestroy($img);

        return $reducida;
    }

    private static function distinto(int $p, int $q): bool
    {
        return abs(($p >> 16 & 255) - ($q >> 16 & 255)) + abs(($p >> 8 & 255) - ($q >> 8 & 255)) + abs(($p & 255) - ($q & 255)) > self::TOLERANCIA;
    }
}
