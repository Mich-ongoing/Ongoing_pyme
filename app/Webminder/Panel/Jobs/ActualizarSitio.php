<?php

namespace App\Webminder\Panel\Jobs;

use App\Webminder\Panel\Models\Ejecucion;
use App\Webminder\Panel\Services\AgenteComunicacionService;
use App\Webminder\Panel\Services\CapturasService;
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

    public function handle(AgenteComunicacionService $agente, CapturasService $capturas): void
    {
        $ejecucion = Ejecucion::with('sitio.credencial')->findOrFail($this->ejecucionId);
        if ($ejecucion->estado !== 'pendiente') {
            return;
        }
        $sitio = $ejecucion->sitio;
        $ejecucion->update(['estado' => 'en_curso', 'iniciada_en' => now()]);
        $progreso = fn (string $texto) => $ejecucion->update(['progreso' => $texto]);

        if ($ejecucion->copia_bd) {
            $ejecucion->update(['progreso' => 'Copiando la base de datos…']);
            $copia = $agente->peticionLarga($sitio, 'backup-db', [], 1800, $progreso);
            if (!$copia['success']) {
                $this->terminar($ejecucion, 'interrumpida', 'No se pudo copiar la base de datos; no se ha actualizado nada: ' . $copia['error']);

                return;
            }
            $ejecucion->update(['archivo_copia_bd' => $copia['file'] ?? null]);
        }

        $errores = 0;
        foreach ($ejecucion->items as $i => $item) {
            $ejecucion->marcarItem($i, 'en_curso');
            $visual = $capturas->activa() && $item['tipo'] !== 'translation';
            if ($visual) {
                // Dos capturas de antes: lo que cambia entre ellas es ruido (sliders, fechas)
                $progreso("Capturando la web antes de actualizar {$item['nombre']}…");
                $antes = $capturas->capturar($sitio->url);
                $antes2 = $capturas->capturar($sitio->url);
            }
            $ejecucion->update(['progreso' => "Actualizando {$item['nombre']}…"]);
            // keep_backup: el worker guarda 7 días la versión anterior para poder revertir desde aquí
            $r = $agente->ejecutarActualizacion($sitio, $item['tipo'], $item['item'], $visual ? ['keep_backup' => true] : [], $progreso);

            if ($r['success']) {
                $estado = ($r['verified'] ?? true) ? 'ok' : 'ok_sin_verificar';
                $mensaje = $r['message'] ?? null;
                $extra = ['backup' => $r['backup'] ?? null, 'ruta' => $r['item'] ?? $item['item']];
                if ($visual) {
                    $progreso("Comparando la web antes y después de {$item['nombre']}…");
                    $extra['visual'] = $capturas->compararYGuardar($ejecucion->id, $i, $antes, $antes2, $capturas->capturar($sitio->url));
                    $medidos = array_filter($extra['visual'], 'is_float');
                    if (!$medidos) {
                        $mensaje = trim($mensaje . ' No se pudo hacer la comparación visual (capturas fallidas).');
                    } elseif (max($medidos) > CapturasService::UMBRAL) {
                        $estado = 'cambio_visual';
                        $mensaje = 'La web se ve distinta tras actualizar. Revisa las capturas y revierte si algo se ha roto.';
                    }
                }
                $ejecucion->marcarItem($i, $estado, $mensaje, $extra);
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
        $ejecucion->update(['estado' => $estado, 'error' => $error, 'progreso' => null, 'terminada_en' => now()]);
    }
}
