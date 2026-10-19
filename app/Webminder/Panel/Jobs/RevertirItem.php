<?php

namespace App\Webminder\Panel\Jobs;

use App\Webminder\Panel\Models\Ejecucion;
use App\Webminder\Panel\Services\AgenteComunicacionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Vuelta atrás manual de un ítem ya actualizado (botón "Revertir" tras un cambio
 * visual): el worker restaura la copia de archivos que guardó al actualizar.
 */
class RevertirItem implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $ejecucionId, public int $i)
    {
    }

    public function handle(AgenteComunicacionService $agente): void
    {
        $ejecucion = Ejecucion::with('sitio.credencial')->findOrFail($this->ejecucionId);
        $item = $ejecucion->items[$this->i];

        $r = $agente->peticionLarga($ejecucion->sitio, 'rollback', [
            'type' => $item['tipo'], 'item' => $item['ruta'] ?? $item['item'], 'backup' => $item['backup'],
        ], 600);

        $ejecucion->marcarItem($this->i, $r['success'] ? 'revertido_manual' : 'error_revertir', $r['success']
            ? "Vuelto a la versión {$item['desde']} desde el panel."
            : 'No se pudo revertir: ' . ($r['error'] ?? 'error desconocido'), $r['success'] ? ['backup' => null] : []);
        $agente->obtenerStatus($ejecucion->sitio);
    }

    public function failed(?\Throwable $e): void
    {
        Ejecucion::find($this->ejecucionId)?->marcarItem($this->i, 'error_revertir', 'El proceso se cortó: ' . ($e?->getMessage() ?? 'desconocido'));
    }
}
