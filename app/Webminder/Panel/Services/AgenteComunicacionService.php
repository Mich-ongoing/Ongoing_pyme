<?php

namespace App\Webminder\Panel\Services;

use Illuminate\Support\Facades\Http;
use App\Webminder\Panel\Models\SitioWeb;
use App\Webminder\Panel\Models\Credencial;
use App\Webminder\Panel\Models\EstadoSitio;
use App\Webminder\Panel\Models\EventoAuditoria;

class AgenteComunicacionService
{
    /**
     * Hacer petición autenticada a un agente remoto
     */
    public function hacerPeticion(
        SitioWeb $sitio,
        string $endpoint,
        array $payload = [],
        string $metodo = 'POST'
    ): array {
        $credencial = $sitio->credencial;

        if (!$credencial || !$credencial->activa) {
            return [
                'success' => false,
                'error' => 'Credenciales no disponibles o inactivas',
            ];
        }

        try {
            $timestamp = time();
            $bodyJson = json_encode($payload);

            // Generar firma HMAC-SHA256
            $signature = hash_hmac('sha256', $bodyJson . $timestamp, $credencial->client_secret);

            $endpointPath = (strtolower($sitio->tecnologia ?? '') === 'wordpress')
                ? "/wp-json/webminder/v1/{$endpoint}"
                : "/api/webminder/{$endpoint}";

            $url = rtrim($sitio->url, '/') . $endpointPath;

            // Registrar utilización de credencial
            $credencial->registrarUso();

            // Hacer petición
            $response = Http::withoutVerifying()
            ->withHeaders([
                'X-Webminder-Signature' => $signature,
                'X-Webminder-Timestamp' => (string)$timestamp,
                'Content-Type' => 'application/json',
            ])
            ->timeout(30)
            ->retry(2, 1000)
            ->send($metodo, $url, [
                'body' => $bodyJson,
            ]);

            $data = $response->json();

            // Registrar evento
            EventoAuditoria::registrarExito(
                'webminder_' . $endpoint,
                "Petición a endpoint: {$endpoint}",
                $sitio->id,
                $data
            );

            return $data ?? ['success' => false];

        } catch (\Exception $e) {
            EventoAuditoria::registrarError(
                'webminder_error',
                "Error en comunicación con {$sitio->nombre}",
                $e->getMessage(),
                $sitio->id
            );

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Obtener estado de un sitio
     */
    public function obtenerStatus(SitioWeb $sitio): array
    {
        $resultado = $this->hacerPeticion($sitio, 'status');

        if ($resultado['success'] ?? false) {
            $esMantenimiento = $resultado['data']['maintenance_mode'] ?? false;
            $sitioEstado = $esMantenimiento ? 'mantenimiento' : 'online';

            // Guardar estado
            EstadoSitio::create([
                'sitio_id' => $sitio->id,
                'estado' => $sitioEstado,
                'datos' => $resultado['data'] ?? [],
                'metricas' => $resultado['configuration'] ?? [],
                'codigo_http' => 200,
                'tiempo_respuesta_ms' => 0,
            ]);

            // Actualizar sitio
            $sitio->update([
                'estado' => $sitioEstado,
                'ultima_verificacion' => now(),
                'version_php' => $resultado['data']['php_version'] ?? null,
                'version_laravel' => $resultado['data']['laravel_version'] ?? null,
            ]);
        }

        return $resultado;
    }

    /**
     * Hacer ping rápido
     */
    public function ping(SitioWeb $sitio): bool
    {
        try {
            $endpointPath = (strtolower($sitio->tecnologia ?? '') === 'wordpress')
                ? '/wp-json/webminder/v1/ping'
                : '/api/webminder/ping';

            $url = rtrim($sitio->url, '/') . $endpointPath;
            $response = Http::withoutVerifying()->timeout(10)->get($url);

            if (!$response->successful()) {
                return false;
            }

            $json = $response->json();

            return (($json['success'] ?? false) === true) || (($json['status'] ?? '') === 'ok');
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Activar mantenimiento remoto
     */
    public function activarMantenimiento(SitioWeb $sitio): array
    {
        return $this->hacerPeticion($sitio, 'maintenance-mode', ['active' => true]);
    }

    /**
     * Desactivar mantenimiento remoto
     */
    public function desactivarMantenimiento(SitioWeb $sitio): array
    {
        return $this->hacerPeticion($sitio, 'maintenance-mode', ['active' => false]);
    }

    /**
     * Limpiar base de datos remota
     */
    public function limpiarBD(SitioWeb $sitio, array $opciones = []): array
    {
        return $this->hacerPeticion($sitio, 'db-clean', $opciones);
    }

    /**
     * Obtener salud de BD remota
     */
    public function obtenerSaludBD(SitioWeb $sitio): array
    {
        return $this->hacerPeticion($sitio, 'db-status');
    }

    /**
     * Obtener actualizaciones pendientes
     */
    public function obtenerActualizaciones(SitioWeb $sitio): array
    {
        return $this->hacerPeticion($sitio, 'updates');
    }

    /**
     * Ejecutar actualización remota
     */
    public function ejecutarActualizacion(SitioWeb $sitio, string $paquete): array
    {
        return $this->hacerPeticion($sitio, 'update', ['package' => $paquete]);
    }

    /**
     * Obtener lista de paquetes
     */
    public function obtenerPaquetes(SitioWeb $sitio): array
    {
        return $this->hacerPeticion($sitio, 'plugins');
    }

    /**
     * Corregir problema de seguridad
     */
    public function corregirSeguridad(SitioWeb $sitio, string $problema): array
    {
        return $this->hacerPeticion($sitio, 'fix-security', ['issue' => $problema]);
    }

    /**
     * Obtener salud completa del sitio
     */
    public function obtenerSaludCompleta(SitioWeb $sitio): array
    {
        return $this->hacerPeticion($sitio, 'site-health');
    }
}
