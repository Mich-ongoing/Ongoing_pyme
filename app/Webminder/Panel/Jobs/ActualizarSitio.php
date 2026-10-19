<?php

namespace App\Webminder\Panel\Jobs;

use App\Webminder\Panel\Models\Ejecucion;
use App\Webminder\Panel\Services\AgenteComunicacionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Ejecuta un lote de actualizaciones: copia de BD (opcional) y luego cada ítem
 * en serie. El rollback por ítem lo hace el worker; aquí se decide parar el
 * lote si se pierde el contacto con el sitio (estado incierto: mejor no seguir).
 */
class ActualizarSitio implements ShouldQueue
{
    use Queueable;

    // Los reintentos solo cubren esperar a otro lote del mismo sitio (release);
    // una excepción no se reintenta: handle() solo arranca lotes 'pendiente'.
    public int $tries = 30;

    public int $maxExceptions = 1;

    public int $timeout = 3600;

    public function __construct(public int $ejecucionId)
    {
    }

    /** Un solo lote a la vez por sitio (el worker además tiene su propio bloqueo). */
    public function middleware(): array
    {
        $sitio = Ejecucion::find($this->ejecucionId)?->sitio_id;

        return [(new WithoutOverlapping('sitio-' . $sitio))->releaseAfter(60)->expireAfter(3700)];
    }

    public function handle(AgenteComunicacionService $agente): void
    {
        $ejecucion = Ejecucion::with('sitio.credencial')->findOrFail($this->ejecucionId);
        if ($ejecucion->estado !== 'pendiente') {
            return;
        }
        $sitio = $ejecucion->sitio;
        $ejecucion->update(['estado' => 'en_curso', 'iniciada_en' => now()]);

        if ($ejecucion->copia_bd) {
            $copia = $agente->hacerPeticion($sitio, 'backup-db', [], null, 900);
            if (!$copia['success']) {
                $this->terminar($ejecucion, 'interrumpida', 'No se pudo copiar la base de datos; no se ha actualizado nada: ' . $copia['error']);

                return;
            }
            $ejecucion->update(['archivo_copia_bd' => $copia['file'] ?? null]);
        }

        $errores = 0;
        foreach ($ejecucion->items as $i => $item) {
            $ejecucion->marcarItem($i, 'en_curso');
            $r = $agente->ejecutarActualizacion($sitio, $item['tipo'], $item['item']);

            if ($r['success']) {
                $ejecucion->marcarItem($i, ($r['verified'] ?? true) ? 'ok' : 'ok_sin_verificar', $r['message'] ?? null);
                continue;
            }
            $errores++;
            $estado = !empty($r['rolled_back']) ? 'revertido' : (!empty($r['requires_license']) ? 'licencia' : 'error');
            $ejecucion->marcarItem($i, $estado, $r['message'] ?? $r['error'] ?? null);

            // Sin respuesta del sitio o rollback fallido: parar, el sitio puede estar a medias
            $sinContacto = str_starts_with((string) ($r['error'] ?? ''), 'Sin conexión');
            if ($sinContacto || (array_key_exists('rolled_back', $r) && !$r['rolled_back'])) {
                foreach (array_slice(array_keys($ejecucion->items), $i + 1) as $resto) {
                    $ejecucion->marcarItem($resto, 'omitido', 'Lote detenido tras un fallo grave en el ítem anterior.');
                }
                $agente->obtenerStatus($sitio);
                $this->terminar($ejecucion, 'interrumpida', 'Lote detenido: ' . ($r['message'] ?? $r['error'] ?? 'fallo grave'));

                return;
            }
        }

        $agente->obtenerStatus($sitio);
        $this->terminar($ejecucion, $errores ? 'con_errores' : 'completada');
    }

    public function failed(?\Throwable $e): void
    {
        $ejecucion = Ejecucion::find($this->ejecucionId);
        if ($ejecucion && !$ejecucion->terminada()) {
            $this->terminar($ejecucion, 'interrumpida', 'El proceso se cortó: ' . ($e?->getMessage() ?? 'desconocido'));
        }
    }

    private function terminar(Ejecucion $ejecucion, string $estado, ?string $error = null): void
    {
        $ejecucion->update(['estado' => $estado, 'error' => $error, 'terminada_en' => now()]);
    }
}
