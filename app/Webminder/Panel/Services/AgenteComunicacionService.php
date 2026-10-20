<?php

namespace App\Webminder\Panel\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use App\Webminder\Panel\Models\SitioWeb;
use App\Webminder\Panel\Models\Credencial;
use App\Webminder\Panel\Models\EstadoSitio;
use App\Webminder\Panel\Models\EventoAuditoria;

class AgenteComunicacionService
{
    public const API = '/webminder/v1/';

    /**
     * Firma v2 (la misma que WebminderAgent::sign en el worker):
     * METODO \n ruta \n timestamp \n cuerpo.
     */
    public static function firmar(string $metodo, string $ruta, int $timestamp, string $cuerpo, string $secreto): string
    {
        return hash_hmac('sha256', strtoupper($metodo) . "\n" . $ruta . "\n" . $timestamp . "\n" . $cuerpo, $secreto);
    }

    /**
     * Petición firmada a un worker. Nunca lanza: devuelve siempre un array con
     * 'success' y, si falla, 'error'.
     */
    public function hacerPeticion(SitioWeb $sitio, string $endpoint, array $payload = [], ?string $secreto = null, int $timeout = 30, bool $auditar = true): array
    {
        $credencial = $sitio->credencial;

        if (!$credencial || !$credencial->activa) {
            return ['success' => false, 'error' => 'Credenciales no disponibles o inactivas'];
        }

        $secreto ??= $credencial->client_secret;
        $ruta = self::API . $endpoint;
        // Nonce: dos peticiones iguales en el mismo segundo (doble clic) tendrían la misma
        // firma y el worker rechazaría la segunda como replay. El worker ignora el campo.
        $cuerpo = json_encode($payload + ['_nonce' => bin2hex(random_bytes(8))]);
        $timestamp = time();

        try {
            // Sin retry(): son POST no idempotentes (un /update reintentado se
            // aplicaría dos veces, o el worker lo rechazaría como replay).
            $response = Http::withHeaders([
                'X-Webminder-Signature-V2' => self::firmar('POST', $ruta, $timestamp, $cuerpo, $secreto),
                // shortcut: firma v1 para los workers < 2.0 (pharmapiping, minestamp); quitar cuando todos estén en 2.x.
                'X-Webminder-Signature' => hash_hmac('sha256', $cuerpo . $timestamp, $secreto),
                'X-Webminder-Timestamp' => (string) $timestamp,
            ])
                ->withBody($cuerpo, 'application/json')
                ->timeout($timeout)
                ->post(rtrim($sitio->url, '/') . '/wp-json' . $ruta);
        } catch (ConnectionException $e) {
            $auditar && EventoAuditoria::registrarError('webminder_' . $endpoint, "Sin conexión con {$sitio->nombre}", $e->getMessage(), $sitio->id);

            return ['success' => false, 'error' => 'Sin conexión: ' . $e->getMessage()];
        }

        $credencial->registrarUso();
        $data = self::normalizar($response->json(), $response->status());

        $resumen = ['http' => $response->status(), 'mensaje' => $data['message'] ?? $data['error'] ?? null];
        if (!$auditar) {
            return $data;
        }
        if ($data['success']) {
            EventoAuditoria::registrarExito('webminder_' . $endpoint, "Petición a {$endpoint}", $sitio->id, $resumen);
        } else {
            EventoAuditoria::registrarError('webminder_' . $endpoint, "Petición a {$endpoint}", (string) ($data['error'] ?? 'Error'), $sitio->id, $resumen);
        }

        return $data;
    }

    /**
     * Operaciones largas (copia de BD, actualización, revisión). El worker ≥ 2.1
     * responde al momento y sigue en segundo plano; aquí se consulta /job hasta
     * tener el resultado. Cloudflare corta a los 100 s cualquier petición (HTTP 524).
     * Un worker anterior ignora 'job' y contesta con el resultado directamente.
     * $reanudar: el $job ya se lanzó (el proceso que lo seguía murió); solo se consulta.
     */
    public function peticionLarga(SitioWeb $sitio, string $endpoint, array $payload, int $limite, ?callable $alProgresar = null, ?string $job = null, bool $reanudar = false): array
    {
        $job ??= Str::random(32);
        if ($reanudar) {
            $inicio = ['success' => false, 'error' => "Sin conexión: se retomó {$endpoint} tras un corte y el sitio no reconoce el trabajo. Revísalo antes de seguir."];
        } else {
            $inicio = $this->hacerPeticion($sitio, $endpoint, $payload + ['job' => $job], null, 90);
            if (($inicio['status'] ?? null) !== 'running' && ($inicio['success'] || !self::sinRespuesta($inicio))) {
                return $inicio;
            }
        }

        // Si el arranque dio 524/timeout la operación puede estar en marcha igual: preguntar
        $desconocido = $fallos = 0;
        for ($i = 0; $i < intdiv($limite, 5); $i++) {
            Sleep::for(5)->seconds();
            $estado = $this->hacerPeticion($sitio, 'job', ['job' => $job], null, 30, false);
            if (($estado['code'] ?? null) === 'rest_no_route') {
                return $inicio; // worker < 2.1
            }
            if (!$estado['success']) {
                // Unos segundos caído es normal (modo mantenimiento al actualizar); un minuto, no
                if (++$fallos >= 12) {
                    return ['success' => false, 'error' => "Sin conexión: el sitio no responde mientras hace {$endpoint}. Revísalo antes de seguir."];
                }
                continue;
            }
            $fallos = 0;
            if (($estado['status'] ?? null) === 'done') {
                $resultado = self::normalizar($estado['result'] ?? null, 200);
                $resumen = ['mensaje' => $resultado['message'] ?? $resultado['error'] ?? null];
                $resultado['success']
                    ? EventoAuditoria::registrarExito('webminder_' . $endpoint, "Resultado de {$endpoint}", $sitio->id, $resumen)
                    : EventoAuditoria::registrarError('webminder_' . $endpoint, "Resultado de {$endpoint}", (string) $resultado['error'], $sitio->id, $resumen);

                return $resultado;
            }
            if (($estado['status'] ?? null) === 'running') {
                if ($alProgresar && isset($estado['progress'])) {
                    $alProgresar((string) $estado['progress']);
                }
                // Sin latido en 5 min el proceso murió en el sitio (lo mató el hosting): no esperar al límite
                if (isset($estado['beat'], $estado['now']) && $estado['now'] - $estado['beat'] > 300) {
                    return ['success' => false, 'error' => "Sin conexión: el proceso de {$endpoint} dejó de avanzar en el sitio (" . ($estado['progress'] ?? 'sin progreso') . '). Revisa el sitio antes de seguir.'];
                }
            }
            if (($estado['status'] ?? null) === 'unknown' && ++$desconocido >= 3) {
                return $inicio; // nunca llegó a arrancar
            }
        }

        return ['success' => false, 'error' => "Sin conexión: el sitio no terminó {$endpoint} en {$limite} s. Revisa el sitio antes de seguir."];
    }

    private static function sinRespuesta(array $r): bool
    {
        return Str::startsWith((string) ($r['error'] ?? ''), ['Sin conexión', 'Respuesta no válida']);
    }

    /**
     * Unifica las respuestas del worker: las suyas ({success, message, ...}) y
     * los WP_Error de WordPress ({code, message, data:{status}}).
     */
    private static function normalizar(mixed $json, int $status): array
    {
        if (!is_array($json)) {
            return ['success' => false, 'error' => "Respuesta no válida del sitio (HTTP {$status})"];
        }
        if (!array_key_exists('success', $json)) {
            // WP_Error de un permission_callback o de una ruta inexistente
            return ['success' => false, 'error' => $json['message'] ?? "Error HTTP {$status}", 'code' => $json['code'] ?? null];
        }
        $json['success'] = (bool) $json['success'];
        if (!$json['success'] && !isset($json['error'])) {
            $json['error'] = $json['message'] ?? 'Error desconocido';
        }

        return $json;
    }

    /**
     * Obtener estado de un sitio y guardarlo en el histórico.
     */
    public function obtenerStatus(SitioWeb $sitio): array
    {
        $resultado = $this->hacerPeticion($sitio, 'status', [], null, 60);

        if ($resultado['success']) {
            $datos = $resultado['data'] ?? [];
            $estado = !empty($datos['maintenance_mode']) ? 'mantenimiento' : 'online';

            EstadoSitio::create([
                'sitio_id' => $sitio->id,
                'estado' => $estado,
                'datos' => $datos,
                'metricas' => [],
                'codigo_http' => 200,
                'tiempo_respuesta_ms' => 0,
            ]);

            $sitio->update([
                'estado' => $estado,
                'ultima_verificacion' => now(),
                'version_php' => $datos['php_version'] ?? null,
            ]);
        } else {
            $sitio->update(['estado' => 'offline', 'ultima_verificacion' => now()]);
        }

        return $resultado;
    }

    /**
     * Ping sin firma (uptime).
     */
    public function ping(SitioWeb $sitio): bool
    {
        try {
            $json = Http::timeout(10)->get(rtrim($sitio->url, '/') . '/wp-json' . self::API . 'ping')->json();

            return ($json['status'] ?? '') === 'ok';
        } catch (ConnectionException) {
            return false;
        }
    }

    public function activarMantenimiento(SitioWeb $sitio): array
    {
        return $this->hacerPeticion($sitio, 'maintenance-mode', ['active' => true]);
    }

    public function desactivarMantenimiento(SitioWeb $sitio): array
    {
        return $this->hacerPeticion($sitio, 'maintenance-mode', ['active' => false]);
    }

    /**
     * @param string[] $items revisions|spam_comments|trashed_comments|transients|auto_drafts|trashed_posts|optimize_tables
     */
    public function limpiarBD(SitioWeb $sitio, array $items): array
    {
        return $this->hacerPeticion($sitio, 'db-clean', ['items' => array_values($items)], null, 120);
    }

    public function obtenerSaludBD(SitioWeb $sitio): array
    {
        return $this->hacerPeticion($sitio, 'db-status');
    }

    public function obtenerActualizaciones(SitioWeb $sitio, bool $refrescar = false): array
    {
        return $this->hacerPeticion($sitio, 'updates', $refrescar ? ['refresh' => true] : [], null, 90);
    }

    /**
     * Actualiza un ítem. $tipo: core|plugin|theme|translation; $item: "carpeta/archivo.php" o slug del tema.
     */
    public function ejecutarActualizacion(SitioWeb $sitio, string $tipo, string $item, array $extra = [], ?callable $alProgresar = null, ?string $job = null, bool $reanudar = false): array
    {
        return $this->peticionLarga($sitio, 'update', ['type' => $tipo, 'item' => $item] + $extra, 900, $alProgresar, $job, $reanudar);
    }

    public function obtenerPaquetes(SitioWeb $sitio): array
    {
        return $this->hacerPeticion($sitio, 'plugins');
    }

    public function corregirSeguridad(SitioWeb $sitio, string $problema): array
    {
        return $this->hacerPeticion($sitio, 'fix-security', ['issue' => $problema]);
    }

    public function obtenerSaludCompleta(SitioWeb $sitio): array
    {
        return $this->hacerPeticion($sitio, 'site-health', [], null, 60);
    }

    public function sso(SitioWeb $sitio): array
    {
        return $this->hacerPeticion($sitio, 'sso');
    }

    /**
     * Rota el secreto sin romper el vínculo: el worker recibe el nuevo firmado
     * con el actual y SOLO se guarda en el panel si una petición firmada con el
     * nuevo funciona (si la respuesta de /rotate se pierde, no quedan desfasados).
     */
    public function rotarSecreto(SitioWeb $sitio): array
    {
        $nuevo = Credencial::generarClientSecret();
        $this->hacerPeticion($sitio, 'rotate', ['secret' => $nuevo]);

        $prueba = $this->hacerPeticion($sitio, 'ping-auth', [], $nuevo);
        if (!$prueba['success']) {
            return ['success' => false, 'error' => 'El sitio no aceptó el secreto nuevo; se mantiene el actual. ' . ($prueba['error'] ?? '')];
        }
        $sitio->credencial->update(['client_secret' => $nuevo]);

        return ['success' => true, 'message' => 'Secreto rotado en el sitio y en el panel.'];
    }

    /**
     * Desvincula: el worker borra sus credenciales y el panel las desactiva.
     */
    public function desconectar(SitioWeb $sitio): array
    {
        $resultado = $this->hacerPeticion($sitio, 'disconnect');
        $sitio->credencial?->update(['activa' => false]);

        return $resultado;
    }
}
