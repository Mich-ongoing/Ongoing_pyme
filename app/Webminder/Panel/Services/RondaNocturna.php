<?php

namespace App\Webminder\Panel\Services;

use App\Webminder\Panel\Jobs\ActualizarSitio;
use App\Webminder\Panel\Models\Ejecucion;
use App\Webminder\Panel\Models\SitioWeb;
use Illuminate\Support\Facades\Cache;

/**
 * Actualización nocturna: un lote por sitio, de uno en uno (no se carga ningún
 * servidor con varios a la vez). Cada lote avanza por pasos cortos en la cola
 * (ActualizarSitio) y, cuando acaba el último, se manda el informe.
 */
class RondaNocturna
{
    private const ESTADOS = [
        'ok' => 'actualizado', 'ok_sin_verificar' => 'actualizado (sin verificar)',
        'cambio_visual' => '⚠ la web se ve distinta, revisa las capturas', 'revertido' => '✖ falló y se revirtió',
        'licencia' => '✖ necesita licencia', 'error' => '✖ error', 'omitido' => 'omitido', 'pendiente' => 'no se llegó a ejecutar',
    ];

    public function __construct(private AgenteComunicacionService $agente)
    {
    }

    /** Lee qué hay pendiente en cada sitio, crea los lotes y arranca el primero. */
    public function planificar(): string
    {
        $ronda = now()->timezone('Europe/Madrid')->format('Y-m-d H:i');
        foreach ($this->sitios() as $sitio) {
            $r = $this->agente->obtenerActualizaciones($sitio, true);
            if (!$r['success']) {
                // Lote vacío ya cerrado: así sale en el informe
                Ejecucion::create(['sitio_id' => $sitio->id, 'ronda' => $ronda, 'copia_bd' => false, 'items' => [], 'estado' => 'interrumpida',
                    'error' => 'No se pudo leer qué hay pendiente: ' . $r['error'], 'terminada_en' => now()]);
                continue;
            }
            if ($items = Ejecucion::items($r['data'] ?? [])) {
                Ejecucion::create(['sitio_id' => $sitio->id, 'ronda' => $ronda, 'copia_bd' => true, 'items' => $items]);
            }
        }
        $this->continuar($ronda);

        return $ronda;
    }

    /** Arranca el siguiente lote de la ronda o, si no queda ninguno, manda el informe (una vez). */
    public function continuar(string $ronda): void
    {
        if (Ejecucion::where('ronda', $ronda)->where('estado', 'en_curso')->exists()) {
            return;
        }
        if ($siguiente = Ejecucion::where('ronda', $ronda)->where('estado', 'pendiente')->orderBy('id')->first()) {
            $siguiente->touch(); // el vigilante no lo vuelve a lanzar mientras espera turno en la cola
            ActualizarSitio::dispatch($siguiente->id);

            return;
        }
        if (Cache::add("watchwp-informe-{$ronda}", true, now()->addDays(3))) {
            $this->informe($ronda);
        }
    }

    /**
     * Cada 5 min: un lote que lleva 90 min sin avanzar se da por cortado (la cola
     * ya habría retomado un proceso muerto), y una ronda parada se relanza.
     */
    public function rescatar(): void
    {
        foreach (Ejecucion::where('estado', 'en_curso')->where('updated_at', '<', now()->subMinutes(90))->get() as $e) {
            foreach ($e->items as $i => $item) {
                if ($item['estado'] === 'en_curso') {
                    $e->marcarItem($i, 'error', 'Se cortó a mitad: comprueba en el sitio si quedó actualizado.');
                }
            }
            $e->terminar('interrumpida', 'El proceso se cortó: 90 min sin avanzar' . ($e->progreso ? " ({$e->progreso})" : '') . '.');
        }
        Ejecucion::where('estado', 'pendiente')->whereNotNull('ronda')->where('updated_at', '<', now()->subMinutes(10))
            ->distinct()->pluck('ronda')->each(fn ($ronda) => $this->continuar($ronda));
    }

    public function informe(string $ronda): void
    {
        $lotes = Ejecucion::with('sitio')->where('ronda', $ronda)->get()->keyBy('sitio_id');
        $lineas = SitioWeb::where('activo', true)->whereNotNull('caida_desde')->get()
            ->map(fn ($s) => "🔴 {$s->nombre} sigue caída desde las " . AvisosService::hora($s->caida_desde))->all();

        foreach ($this->sitios() as $sitio) {
            $e = $lotes->get($sitio->id);
            if (!$e) {
                $lineas[] = "✔ {$sitio->nombre}: todo al día";
                continue;
            }
            $bloque = ($e->estado === 'completada' ? '✔' : '✖') . " {$sitio->nombre}: " . str_replace('_', ' ', $e->estado) . ($e->error ? " — {$e->error}" : '');
            foreach ($e->items as $item) {
                $version = $item['tipo'] === 'translation' ? "({$item['hasta']} paquetes)" : "{$item['desde']} → {$item['hasta']}";
                $bloque .= "\n    · {$item['nombre']} {$version}: " . (self::ESTADOS[$item['estado']] ?? $item['estado'])
                    . (!in_array($item['estado'], ['ok', 'pendiente'], true) && $item['mensaje'] ? " ({$item['mensaje']})" : '');
            }
            $lineas[] = $bloque . ($e->items ? "\n    " . route('webminder-panel.ejecuciones.show', $e) : '');
        }

        AvisosService::enviar('Informe de mantenimiento ' . substr($ronda, 0, 10), implode("\n\n", $lineas) ?: 'No hay sitios vinculados.');
    }

    private function sitios()
    {
        return SitioWeb::where('activo', true)->whereHas('credencial', fn ($q) => $q->where('activa', true))->orderBy('nombre')->get();
    }
}
