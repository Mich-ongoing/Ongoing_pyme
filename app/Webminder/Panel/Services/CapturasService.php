<?php

namespace App\Webminder\Panel\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Comparación visual antes/después de una actualización con Chrome sin pantalla
 * (Browserless en Docker, solo accesible desde el servidor del panel).
 * Sin WEBMINDER_CAPTURAS_URL en el .env la función queda apagada.
 */
class CapturasService
{
    public const VISTAS = [
        'escritorio' => ['width' => 1366, 'height' => 900],
        'movil' => ['width' => 390, 'height' => 844, 'isMobile' => true, 'hasTouch' => true],
    ];

    /** % de la página que puede cambiar sin avisar. */
    public const UMBRAL = 3.0;

    // Se compara a 1/4 de resolución: menos memoria y no salta por antialiasing
    private const ESCALA = 4;

    // Suma de diferencias RGB a partir de la cual un píxel cuenta como distinto
    private const TOLERANCIA = 60;

    public function activa(): bool
    {
        return (bool) config('services.capturas.url');
    }

    /**
     * Captura de página completa en cada vista, en paralelo.
     * @return array<string,?string> vista => jpeg, o null si esa captura falló
     */
    public function capturar(string $url): array
    {
        // Parámetro propio: que ninguna caché de página sirva la versión de antes
        $url .= (str_contains($url, '?') ? '&' : '?') . 'webminder_check=' . Str::random(8);
        $endpoint = rtrim(config('services.capturas.url'), '/') . '/chromium/screenshot?token=' . urlencode((string) config('services.capturas.token'));

        $respuestas = Http::pool(fn (Pool $pool) => collect(self::VISTAS)->map(fn ($viewport, $vista) => $pool->as($vista)->timeout(90)->post($endpoint, [
            'url' => $url,
            'viewport' => $viewport,
            'options' => ['type' => 'jpeg', 'quality' => 70, 'fullPage' => true],
            'gotoOptions' => ['waitUntil' => 'networkidle2', 'timeout' => 45000],
            // Animaciones y cursores parpadeantes darían diferencias falsas
            'addStyleTag' => [['content' => '*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}']],
            'bestAttempt' => true,
        ]))->all());

        return array_map(fn ($r) => $r instanceof Response && $r->successful() && str_starts_with($r->body(), "\xFF\xD8") ? $r->body() : null, $respuestas);
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
     * Guarda las capturas de un ítem y su comparación.
     * @param array<string,?string> $antes
     * @param array<string,?string> $antes2
     * @param array<string,?string> $despues
     * @return array<string,?float> vista => % cambiado (null si faltó alguna captura)
     */
    public function compararYGuardar(int $ejecucionId, int $i, array $antes, array $antes2, array $despues): array
    {
        $resultado = [];
        foreach (array_keys(self::VISTAS) as $vista) {
            if (!$antes[$vista] || !$antes2[$vista] || !$despues[$vista]) {
                $resultado[$vista] = null;
                continue;
            }
            [$porcentaje, $diff] = $this->comparar($antes[$vista], $antes2[$vista], $despues[$vista]);
            foreach (['antes' => $antes[$vista], 'despues' => $despues[$vista], 'diff' => $diff] as $tipo => $jpg) {
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
