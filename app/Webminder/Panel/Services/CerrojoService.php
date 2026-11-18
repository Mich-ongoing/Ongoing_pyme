<?php

namespace App\Webminder\Panel\Services;

use App\Webminder\Panel\Models\SitioWeb;

/**
 * Cerrojo desde watchWP. Todo lo que puede meter código o abrir la web va firmado con la
 * clave privada del panel (la de Injerto), que las webs no tienen: el secreto de cada sitio
 * está en su BD y no basta para instalar código ni para abrir la instalación de plugins.
 */
class CerrojoService
{
    public function __construct(private AgenteComunicacionService $agente)
    {
    }

    public static function codigo(): ?string
    {
        $archivo = (string) config('services.cerrojo.archivo');

        return is_file($archivo) ? file_get_contents($archivo) : null;
    }

    /**
     * Falsa verificación de Cloudflare (ClickFix) en la página tal como la ve un visitante. Es la misma idea que
     * cerrojo_clickfix() del plugin, pero desde fuera: se ve aunque el código esté donde Cerrojo no mira, y el
     * cargador se esconde a los administradores pero no a watchWP. Visto en bleyabogados.es, minestamp.com y cuneocalcio.it (18-nov).
     */
    public static function falsoCloudflare(string $html): ?string
    {
        // <script src="data:text/javascript;base64,…"> (LiteSpeed): se mira también lo que lleva dentro
        $html = preg_replace_callback('~data:(?:text|application)/(?:x-)?javascript;base64,([A-Za-z0-9+/=]{20,})~i',
            fn ($m) => $m[0] . "\n" . base64_decode($m[1]), $html);
        $script = 'createElement\s*\(\s*["\']script';
        $patrones = [
            'copia un comando al portapapeles y pide ejecutarlo' => '~(clipboard\s*\.\s*writeText|execCommand\s*\(\s*["\']copy).{0,3000}?\b(powershell|mshta|cmd(\.exe)?\s+/[ck]|rundll32|msiexec|Invoke-Expression|iex)\b|\b(powershell|mshta|rundll32|msiexec)\b.{0,3000}?(clipboard\s*\.\s*writeText|execCommand\s*\(\s*["\']copy)~is',
            'carga scripts de direcciones cifradas (XOR)' => "~charCodeAt\\s*\\([^)]*\\)\\s*\\^\\s*\\w+\\s*\\.\\s*charCodeAt.{0,1500}?$script|$script.{0,1500}?charCodeAt\\s*\\([^)]*\\)\\s*\\^\\s*\\w+\\s*\\.\\s*charCodeAt~is",
            'lee código de la blockchain (EtherHiding)' => '~eth_call.{0,3000}?(bsc|binance)|(bsc-dataseed|data-seed-prebsc|bsc-testnet)[\w.-]*.{0,3000}?eth_call~is',
        ];
        preg_match_all('~<script\b[^>]*>.*?</script\s*>~is', $html, $scripts);
        foreach ($scripts[0] as $s) {
            foreach ($patrones as $motivo => $re) {
                if (preg_match($re, $s)) {
                    return $motivo;
                }
            }
        }

        return null;
    }

    /** Versión que el panel tiene para instalar, o null si no se ha subido el archivo. */
    public static function version(): ?string
    {
        return preg_match('/^\s*\*\s*Version:\s*([0-9.]+)/m', (string) self::codigo(), $m) ? $m[1] : null;
    }

    /** Lo último que contó el worker de Cerrojo en este sitio (null si nunca). */
    public static function estado(SitioWeb $sitio): ?array
    {
        return $sitio->estadoActual()?->datos['cerrojo'] ?? null;
    }

    /** Mismo formato que cerrojo_mensaje_permiso() en el plugin: si cambia uno, cambia el otro. */
    public static function mensajePermiso(string $host, int $hasta): string
    {
        return 'cerrojo|instalar|' . strtolower($host) . '|' . $hasta;
    }

    /** Mismo formato que cerrojo_mensaje_admin() en el plugin. */
    public static function mensajeAdmin(string $host, string $login, int $hasta): string
    {
        return 'cerrojo|admin|' . strtolower($host) . '|' . $login . '|' . $hasta;
    }

    /** Administradores, bloqueados por intentos y degradados por Cerrojo (necesita Cerrojo 1.6 y worker 2.7). */
    public function usuarios(SitioWeb $sitio): array
    {
        return $this->agente->hacerPeticion($sitio, 'cerrojo', ['accion' => 'usuarios']);
    }

    /** Lo que hacía «wp cerrojo confiar»: quita el bloqueo por intentos fallidos. */
    public function desbloquear(SitioWeb $sitio, string $login): array
    {
        return $this->agente->hacerPeticion($sitio, 'cerrojo', ['accion' => 'desbloquear', 'login' => $login]);
    }

    /** Lo que hacía «wp cerrojo admin»: firmado, porque crea un administrador. La firma vale 10 min. */
    public function autorizarAdmin(SitioWeb $sitio, string $login): array
    {
        $host = self::estado($sitio)['host'] ?? (string) parse_url($sitio->url, PHP_URL_HOST);
        $hasta = now()->addMinutes(10)->timestamp;

        return $this->agente->hacerPeticion($sitio, 'cerrojo', [
            'accion' => 'admin', 'login' => $login, 'hasta' => $hasta,
            'firma' => InjertoService::firmarMensaje(self::mensajeAdmin($host, $login, $hasta)),
        ]);
    }

    public function instalar(SitioWeb $sitio): array
    {
        $codigo = self::codigo() ?? throw new \RuntimeException('Falta el archivo de Cerrojo en el panel (' . config('services.cerrojo.archivo') . ').');
        $r = $this->agente->hacerPeticion($sitio, 'cerrojo', [
            'accion' => 'instalar', 'codigo' => $codigo,
            'firma' => InjertoService::firmarMensaje('cerrojo|codigo|' . hash('sha256', $codigo)),
        ], null, 120);
        $this->agente->obtenerStatus($sitio);

        return $r;
    }

    /** Abre la instalación de plugins y temas durante $minutos. */
    public function permitir(SitioWeb $sitio, int $minutos): array
    {
        // El dominio con que firma Cerrojo es el de su home_url, que puede no coincidir con la URL dada de alta aquí
        $host = self::estado($sitio)['host'] ?? (string) parse_url($sitio->url, PHP_URL_HOST);
        $hasta = now()->addMinutes($minutos)->timestamp;
        $r = $this->agente->hacerPeticion($sitio, 'cerrojo', [
            'accion' => 'permiso', 'hasta' => $hasta, 'firma' => InjertoService::firmarMensaje(self::mensajePermiso($host, $hasta)),
        ]);
        $this->agente->obtenerStatus($sitio);

        return $r;
    }

    public function cerrar(SitioWeb $sitio): array
    {
        $r = $this->agente->hacerPeticion($sitio, 'cerrojo', ['accion' => 'permiso', 'hasta' => 0]);
        $this->agente->obtenerStatus($sitio);

        return $r;
    }

    /** Limpia lo que Cerrojo sabe limpiar (archivos del ataque, BD, plugins); puede tardar minutos. */
    public function limpiar(SitioWeb $sitio): array
    {
        $r = $this->agente->peticionLarga($sitio, 'cerrojo', ['accion' => 'limpiar'], 600);
        $this->agente->obtenerStatus($sitio);

        return $r;
    }
}
