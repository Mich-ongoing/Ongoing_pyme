<?php

namespace App\Webminder\Panel\Jobs;

use App\Webminder\Panel\Models\Ejecucion;
use App\Webminder\Panel\Services\AgenteComunicacionService;
use App\Webminder\Panel\Services\CapturasService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;

/**
 * Ejecuta un lote de actualizaciones por pasos: cada ejecución del job hace un
 * solo paso (la copia de BD o un ítem) y encola el siguiente. Así ningún proceso
 * dura más que un paso, y si uno muere la cola lo repite y retoma el trabajo que
 * sigue corriendo en el sitio (job_remoto). El rollback por ítem lo hace el
 * worker; aquí se para el lote si se pierde el contacto (estado incierto).
 */
class ActualizarSitio implements ShouldQueue
{
    use Queueable;

    // Los reintentos solo cubren esperar a otro lote del mismo sitio (release)
    // o retomar un proceso muerto; una excepción no se reintenta.
    public int $tries = 30;

    public int $maxExceptions = 1;

    // Un paso: copia de BD (hasta 1800 s) o un ítem (900 s + capturas). Menor que retry_after de la cola.
    public int $timeout = 2400;

    public function __construct(public int $ejecucionId)
    {
    }

    /** Un solo lote a la vez por sitio (el worker además tiene su propio bloqueo). */
    public function middleware(): array
    {
        $sitio = Ejecucion::find($this->ejecucionId)?->sitio_id;

        return [(new WithoutOverlapping('sitio-' . $sitio))->releaseAfter(60)->expireAfter(2500)];
    }

    public function handle(AgenteComunicacionService $agente, CapturasService $capturas): void
    {
        $ejecucion = Ejecucion::with('sitio.credencial')->findOrFail($this->ejecucionId);
        if ($ejecucion->terminada()) {
            return;
        }
        $sitio = $ejecucion->sitio;
        if ($ejecucion->estado === 'pendiente') {
            $ejecucion->update(['estado' => 'en_curso', 'iniciada_en' => now()]);
        }
        $progreso = fn (string $texto) => $ejecucion->update(['progreso' => $texto]);

        if ($ejecucion->copia_bd && !$ejecucion->archivo_copia_bd) {
            $ejecucion->update(['progreso' => 'Copiando la base de datos…']);
            $copia = $this->remoto($ejecucion, fn ($job, $reanudar) => $agente->peticionLarga($sitio, 'backup-db', [], 1800, $progreso, $job, $reanudar));
            if (!$copia['success']) {
                $ejecucion->terminar('interrumpida', 'No se pudo copiar la base de datos; no se ha actualizado nada: ' . $copia['error']);

                return;
            }
            $ejecucion->update(['archivo_copia_bd' => $copia['file'] ?? 'hecha']);
            $this->siguientePaso();

            return;
        }

        $i = collect($ejecucion->items)->search(fn ($it) => in_array($it['estado'], ['en_curso', 'pendiente'], true));
        if ($i !== false) {
            if (!$this->ejecutarItem($ejecucion, $i, $agente, $capturas, $progreso)) {
                return;
            }
            if (collect($ejecucion->items)->contains('estado', 'pendiente')) {
                $this->siguientePaso();

                return;
            }
        }

        $agente->obtenerStatus($sitio);
        $errores = collect($ejecucion->items)->whereIn('estado', ['revertido', 'licencia', 'error'])->count();
        $ejecucion->terminar($errores ? 'con_errores' : 'completada');
    }

    /** Actualiza el ítem $i. false si el lote se ha detenido. */
    private function ejecutarItem(Ejecucion $ejecucion, int $i, AgenteComunicacionService $agente, CapturasService $capturas, callable $progreso): bool
    {
        $sitio = $ejecucion->sitio;
        $item = $ejecucion->items[$i];
        $ejecucion->marcarItem($i, 'en_curso');
        // Al retomar tras un corte no hay capturas de antes: sin comparación visual
        $visual = !$ejecucion->job_remoto && $capturas->activa() && $item['tipo'] !== 'translation';
        if ($visual) {
            // Dos capturas de antes: lo que cambia entre ellas es ruido (sliders, fechas)
            $progreso("Capturando la web antes de actualizar {$item['nombre']}…");
            $antes = $capturas->capturar($sitio->url);
            $antes2 = $capturas->capturar($sitio->url);
        }
        $ejecucion->update(['progreso' => "Actualizando {$item['nombre']}…"]);
        // keep_backup: el worker guarda 7 días la versión anterior para poder revertir desde aquí
        $r = $this->remoto($ejecucion, fn ($job, $reanudar) => $agente->ejecutarActualizacion(
            $sitio, $item['tipo'], $item['item'], $visual ? ['keep_backup' => true] : [], $progreso, $job, $reanudar));

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

            return true;
        }
        $estado = !empty($r['rolled_back']) ? 'revertido' : (!empty($r['requires_license']) ? 'licencia' : 'error');
        $ejecucion->marcarItem($i, $estado, $r['message'] ?? $r['error'] ?? null);

        // Sin respuesta del sitio o rollback fallido: parar, el sitio puede estar a medias
        $sinContacto = str_starts_with((string) ($r['error'] ?? ''), 'Sin conexión');
        if ($sinContacto || (array_key_exists('rolled_back', $r) && !$r['rolled_back'])) {
            foreach (array_slice(array_keys($ejecucion->items), $i + 1) as $resto) {
                $ejecucion->marcarItem($resto, 'omitido', 'Lote detenido tras un fallo grave en el ítem anterior.');
            }
            $agente->obtenerStatus($sitio);
            $ejecucion->terminar('interrumpida', 'Lote detenido: ' . ($r['message'] ?? $r['error'] ?? 'fallo grave'));

            return false;
        }

        return true;
    }

    /** Lanza (o retoma, si el proceso anterior murió a mitad) una operación larga en el sitio. */
    private function remoto(Ejecucion $ejecucion, callable $peticion): array
    {
        $reanudar = $ejecucion->job_remoto !== null;
        $job = $ejecucion->job_remoto ?? Str::random(32);
        $ejecucion->update(['job_remoto' => $job]);
        $r = $peticion($job, $reanudar);
        $ejecucion->update(['job_remoto' => null]);

        return $r;
    }

    /** Pausa corta entre pasos: no encadenar operaciones pesadas en el sitio sin respiro. */
    private function siguientePaso(): void
    {
        self::dispatch($this->ejecucionId)->delay(now()->addSeconds(10));
    }

    public function failed(?\Throwable $e): void
    {
        $ejecucion = Ejecucion::find($this->ejecucionId);
        if ($ejecucion && !$ejecucion->terminada()) {
            $ejecucion->terminar('interrumpida', 'El proceso se cortó: ' . ($e?->getMessage() ?? 'desconocido'));
        }
    }
}
