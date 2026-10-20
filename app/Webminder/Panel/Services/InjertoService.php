<?php

namespace App\Webminder\Panel\Services;

use App\Models\User;
use App\Webminder\Panel\Models\InjertoSolicitud;
use App\Webminder\Panel\Models\SitioWeb;

/**
 * Injerto con watchWP:
 * - B) Las webs piden aquí publicar código; se aprueba en el panel y se firma con una
 *   clave privada Ed25519 que solo tiene el panel. Las webs comprueban con la pública.
 * - A) En la vigilancia de cada 5 min se apuntan los dominios de los scripts de la
 *   portada (solo dominios externos: el código de dentro cambia solo y daría ruido);
 *   uno nuevo que nadie ha aprobado manda un aviso.
 */
class InjertoService
{
    public const ZONAS = ['head', 'body', 'footer', 'jsonld'];

    public function __construct(private AgenteComunicacionService $agente)
    {
    }

    /** Mismo formato que injerto_mensaje() en el plugin: si cambia uno, cambia el otro. */
    public static function mensaje(int $ambito, array $valores): string
    {
        return 'injerto|' . $ambito . '|' . json_encode(self::normalizar($valores));
    }

    public static function normalizar(array $valores): array
    {
        return collect(self::ZONAS)->mapWithKeys(fn ($z) => [$z => trim((string) ($valores[$z] ?? ''))])->all();
    }

    public static function firmar(int $ambito, array $valores): string
    {
        $privada = base64_decode((string) config('services.injerto.clave_privada'), true);
        if (!$privada || strlen($privada) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('Falta WEBMINDER_INJERTO_CLAVE (clave privada de Injerto) en el .env');
        }

        return base64_encode(sodium_crypto_sign_detached(self::mensaje($ambito, $valores), $privada));
    }

    /** Llega por la ingesta del worker (evento injerto_solicitud). Un reenvío no duplica. */
    public function recibir(int $sitioId, array $d): void
    {
        $solicitud = InjertoSolicitud::firstOrCreate(
            ['sitio_id' => $sitioId, 'remoto_id' => mb_substr((string) ($d['id'] ?? ''), 0, 32)],
            [
                'ambito' => (int) ($d['ambito'] ?? 0),
                'ambito_nombre' => mb_substr((string) ($d['ambito_nombre'] ?? ''), 0, 255),
                'valores' => self::normalizar((array) ($d['valores'] ?? [])),
                'anteriores' => self::normalizar((array) ($d['anteriores'] ?? [])),
                'usuario' => mb_substr((string) ($d['usuario'] ?? ''), 0, 255),
                'ip' => mb_substr((string) ($d['ip'] ?? ''), 0, 64),
            ]
        );
        if (!$solicitud->wasRecentlyCreated || $solicitud->remoto_id === '') {
            return;
        }
        // Una petición nueva para el mismo sitio y ámbito deja obsoletas las anteriores
        InjertoSolicitud::where('sitio_id', $sitioId)->where('ambito', $solicitud->ambito)->where('estado', 'pendiente')
            ->where('id', '<', $solicitud->id)->update(['estado' => 'rechazada', 'error' => 'Sustituida por una petición posterior.']);

        $sitio = $solicitud->sitio;
        AvisosService::enviar("Aprobar código de Injerto en {$sitio->nombre}",
            "{$solicitud->usuario} pide publicar código en {$solicitud->ambito_nombre} de {$sitio->url} (IP {$solicitud->ip}).\n\n"
            . "Revisar y aprobar en watchWP:\n" . route('webminder-panel.injerto.show', $solicitud) . "\n\n"
            . self::resumen($solicitud->valores, $solicitud->anteriores));
    }

    public function aprobar(InjertoSolicitud $s, ?User $usuario): bool
    {
        $r = $this->agente->injerto($s->sitio, [
            'id' => $s->remoto_id, 'aprobar' => true, 'ambito' => $s->ambito,
            'valores' => $s->valores, 'firma' => self::firmar($s->ambito, $s->valores),
        ]);
        $s->update(['estado' => $r['success'] ? 'aprobada' : 'fallida', 'error' => $r['success'] ? null : ($r['error'] ?? 'Error desconocido'),
            'decidida_por' => $usuario?->id, 'decidida_en' => now()]);
        if ($r['success']) {
            // Lo aprobado deja de ser «desconocido» para la vigilancia de scripts
            $this->conocer($s->sitio, self::dominios(implode("\n", $s->valores), $s->sitio->url));
        }

        return $r['success'];
    }

    public function rechazar(InjertoSolicitud $s, ?User $usuario): void
    {
        // Si la web no contesta da igual: sin firma no se publica nada
        $this->agente->injerto($s->sitio, ['id' => $s->remoto_id, 'aprobar' => false, 'ambito' => $s->ambito]);
        $s->update(['estado' => 'rechazada', 'decidida_por' => $usuario?->id, 'decidida_en' => now()]);
    }

    /** Dominios externos de los scripts del HTML (src y URLs dentro de scripts en línea). */
    public static function dominiosScripts(string $html, string $urlSitio): array
    {
        preg_match_all('#<script\b([^>]*)>(.*?)</script>#is', $html, $m, PREG_SET_ORDER);
        $texto = '';
        foreach ($m as [, $atributos, $codigo]) {
            // Datos, no código: JSON-LD (lleva URLs de redes sociales, schema.org…) y plantillas
            if (preg_match('#type\s*=\s*["\']?(application/(ld\+)?json|text/(template|html|x-template))#i', $atributos)) {
                continue;
            }
            $texto .= $atributos . "\n" . $codigo . "\n";
        }

        return self::dominios($texto, $urlSitio);
    }

    public static function dominios(string $texto, string $urlSitio): array
    {
        // También URLs escapadas en JSON (https:\/\/…)
        preg_match_all('#(?:https?:)?\\\\?/\\\\?/([a-z0-9][a-z0-9.-]*\.[a-z]{2,})#i', $texto, $m);
        $propio = preg_replace('/^www\./', '', strtolower((string) parse_url($urlSitio, PHP_URL_HOST)));

        return collect($m[1])->map(fn ($d) => strtolower(rtrim($d, '.')))
            ->reject(fn ($d) => preg_replace('/^www\./', '', $d) === $propio)
            ->unique()->sort()->values()->all();
    }

    /** Llamado por webminder:vigilar con la portada ya descargada. */
    public function revisarScripts(SitioWeb $sitio, string $html): void
    {
        $vistos = self::dominiosScripts($html, $sitio->url);
        $estado = $sitio->dominios_scripts;
        if ($estado === null) {
            // Primera vez: lo que hay es la referencia, sin avisar
            $sitio->update(['dominios_scripts' => ['conocidos' => $vistos, 'avisados' => []]]);

            return;
        }
        $nuevos = array_values(array_diff($vistos, $estado['conocidos'] ?? [], $estado['avisados'] ?? []));
        if (!$nuevos) {
            return;
        }
        $estado['avisados'] = array_values(array_unique(array_merge($estado['avisados'] ?? [], $nuevos)));
        $sitio->update(['dominios_scripts' => $estado]);
        AvisosService::enviar("⚠ Script externo nuevo en {$sitio->nombre}",
            "La portada de {$sitio->url} carga scripts de dominios que no estaban ni se han aprobado:\n\n  · " . implode("\n  · ", $nuevos)
            . "\n\nSi es de algo instalado a propósito (un plugin, un chat…), acéptalo en watchWP. Si no, puede ser código inyectado: revisa la web.\n"
            . route('webminder-panel.injerto.index'));
    }

    public function conocer(SitioWeb $sitio, array $dominios): void
    {
        $estado = $sitio->dominios_scripts ?? ['conocidos' => [], 'avisados' => []];
        $estado['conocidos'] = array_values(array_unique(array_merge($estado['conocidos'] ?? [], $dominios)));
        $estado['avisados'] = array_values(array_diff($estado['avisados'] ?? [], $dominios));
        $sitio->update(['dominios_scripts' => $estado]);
    }

    /** Olvidar un aviso: si el dominio vuelve a aparecer, se avisa otra vez. */
    public function olvidar(SitioWeb $sitio, string $dominio): void
    {
        $estado = $sitio->dominios_scripts ?? [];
        $estado['avisados'] = array_values(array_diff($estado['avisados'] ?? [], [$dominio]));
        $sitio->update(['dominios_scripts' => $estado]);
    }

    /** Avisos de cosas típicas de código malicioso (no bloquean: decide quien aprueba). */
    public static function sospechas(string $codigo): array
    {
        return collect([
            'eval(' => '#\beval\s*\(#i', 'atob(' => '#\batob\s*\(#i', 'document.write' => '#document\s*\.\s*write#i',
            'String.fromCharCode' => '#fromCharCode#i', 'unescape(' => '#\bunescape\s*\(#i', 'new Function' => '#new\s+Function\s*\(#i',
            'texto en hexadecimal' => '#(\\\\x[0-9a-f]{2}){8,}#i',
        ])->filter(fn ($patron) => preg_match($patron, $codigo))->keys()->all();
    }

    public static function resumen(array $valores, ?array $antes = null): string
    {
        $titulos = ['head' => 'Dentro de <head>', 'body' => 'Justo después de <body>', 'footer' => 'Antes de </body>', 'jsonld' => 'JSON-LD (Schema)'];
        $todo = implode("\n", $valores);
        $dominios = self::dominios($todo, '');
        $texto = 'Dominios externos que carga: ' . ($dominios ? implode(', ', $dominios) : 'ninguno') . "\n";
        if ($sospechas = self::sospechas($todo)) {
            $texto .= '⚠ ATENCIÓN, contiene: ' . implode(', ', $sospechas) . ". Es habitual en código malicioso: apruébalo solo si sabes de dónde sale.\n";
        }
        foreach ($titulos as $zona => $titulo) {
            $cambia = $antes !== null && ($antes[$zona] ?? '') !== ($valores[$zona] ?? '');
            if (($valores[$zona] ?? '') === '' && !$cambia) {
                continue;
            }
            $texto .= "\n=== {$titulo}" . ($cambia ? ' (CAMBIA)' : '') . " ===\n" . (($valores[$zona] ?? '') !== '' ? $valores[$zona] : '(vacío: se quita)') . "\n";
        }

        return $texto;
    }
}
