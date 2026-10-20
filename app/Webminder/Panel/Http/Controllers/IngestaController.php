<?php

namespace App\Webminder\Panel\Http\Controllers;

use App\Webminder\Panel\Models\Credencial;
use App\Webminder\Panel\Models\EventoActividad;
use App\Webminder\Panel\Services\AgenteComunicacionService;
use App\Webminder\Panel\Services\InjertoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Recibe el log de actividad que empujan los workers (WebminderActivityLog::push_pending).
 */
class IngestaController
{
    public const RUTA = '/api/webminder/ingest';

    private const TOLERANCIA = 900;

    public function __invoke(Request $request): JsonResponse
    {
        $timestamp = (int) $request->header('X-Webminder-Timestamp');
        $firma = (string) $request->header('X-Webminder-Signature-V2');
        $credencial = Credencial::where('client_id', (string) $request->header('X-Webminder-Client'))
            ->where('activa', true)->first();

        if (!$credencial || !$firma || abs(time() - $timestamp) > self::TOLERANCIA) {
            return response()->json(['success' => false, 'error' => 'No autorizado'], 401);
        }

        $esperada = AgenteComunicacionService::firmar('POST', self::RUTA, $timestamp, $request->getContent(), $credencial->client_secret);
        if (!hash_equals($esperada, $firma)) {
            return response()->json(['success' => false, 'error' => 'Firma inválida'], 401);
        }

        $datos = $request->validate([
            'client_id' => ['required', 'in:' . $credencial->client_id],
            'events' => ['required', 'array', 'max:500'],
            'events.*.local_id' => ['required', 'integer', 'min:1'],
            'events.*.occurred_at' => ['required', 'date'],
            'events.*.action' => ['required', 'string', 'max:100'],
        ]);

        $filas = [];
        foreach ($request->input('events') as $e) {
            $filas[] = [
                'sitio_id' => $credencial->sitio_id,
                'local_id' => (int) $e['local_id'],
                'ocurrido_en' => Carbon::parse($e['occurred_at'])->utc(),
                'actor_login' => self::corto($e['actor_login'] ?? null, 255),
                'actor_email' => self::corto($e['actor_email'] ?? null, 255),
                'actor_rol' => self::corto($e['actor_role'] ?? null, 100),
                'ip' => self::corto($e['ip'] ?? null, 64),
                'accion' => self::corto($e['action'], 100),
                'objeto_tipo' => self::corto($e['object_type'] ?? null, 50),
                'objeto_nombre' => self::corto($e['object_name'] ?? null, 255),
                'severidad' => ($e['severity'] ?? '') === 'critical' ? 'critical' : 'info',
                'detalles' => isset($e['details']) && is_array($e['details']) ? json_encode($e['details']) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        // insertOrIgnore: un reenvío del mismo lote no duplica (unique sitio_id+local_id)
        EventoActividad::insertOrIgnore($filas);

        // Injerto pide aprobar código: llega como evento del log de actividad
        foreach ($request->input('events') as $e) {
            if ($e['action'] === 'injerto_solicitud' && is_array($e['details'] ?? null)) {
                app(InjertoService::class)->recibir($credencial->sitio_id, $e['details']);
            }
        }

        return response()->json(['success' => true, 'accepted' => array_column($filas, 'local_id')]);
    }

    private static function corto(mixed $valor, int $max): ?string
    {
        return is_scalar($valor) && $valor !== '' ? mb_substr((string) $valor, 0, $max) : null;
    }
}
