<?php

namespace App\Webminder\Panel\Http\Controllers;

use App\Webminder\Panel\Jobs\ActualizarSitio;
use App\Webminder\Panel\Jobs\RevertirItem;
use App\Webminder\Panel\Services\CapturasService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Webminder\Panel\Models\Ejecucion;
use App\Webminder\Panel\Models\SitioWeb;
use App\Webminder\Panel\Services\AgenteComunicacionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActualizacionesController
{
    public function __construct(protected AgenteComunicacionService $agente)
    {
    }

    /** Lista de pendientes en el sitio para elegir qué actualizar. */
    public function index(Request $request, SitioWeb $sitio): View
    {
        $resultado = $this->agente->obtenerActualizaciones($sitio, $request->boolean('refrescar'));

        return view('webminder-panel::sitios.actualizaciones', [
            'sitio' => $sitio,
            'pendientes' => $resultado['success'] ? ($resultado['data'] ?? []) : [],
            'error' => $resultado['success'] ? null : $resultado['error'],
            'enCurso' => Ejecucion::where('sitio_id', $sitio->id)->whereIn('estado', ['pendiente', 'en_curso'])->latest()->first(),
            'historial' => Ejecucion::where('sitio_id', $sitio->id)->latest()->limit(10)->get(),
        ]);
    }

    /** Crea el lote y lo manda a la cola. */
    public function store(Request $request, SitioWeb $sitio): RedirectResponse
    {
        $validado = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['string', 'max:300'],
            'copia_bd' => ['boolean'],
        ]);

        // Nombres y versiones salen del sitio, no del formulario
        $resultado = $this->agente->obtenerActualizaciones($sitio);
        if (!$resultado['success']) {
            return back()->with('error', 'No se pudo leer la lista de actualizaciones: ' . $resultado['error']);
        }
        $pendientes = collect($resultado['data'] ?? [])->keyBy(fn ($u) => $u['type'] . '|' . $u['item']);

        $items = Ejecucion::items($pendientes->only($validado['items'])->all());
        if (!$items) {
            return back()->with('error', 'Ninguno de los elementos elegidos sigue pendiente en el sitio.');
        }

        $ejecucion = Ejecucion::create([
            'sitio_id' => $sitio->id,
            'usuario_id' => $request->user()?->id,
            'copia_bd' => $request->boolean('copia_bd'),
            'items' => $items,
        ]);
        ActualizarSitio::dispatch($ejecucion->id);

        return redirect()->route('webminder-panel.ejecuciones.show', $ejecucion);
    }

    public function show(Ejecucion $ejecucion): View
    {
        return view('webminder-panel::sitios.ejecucion', ['ejecucion' => $ejecucion->load('sitio', 'usuario')]);
    }

    /** Vuelve a la versión anterior un ítem ya actualizado (copia guardada 7 días en el sitio). */
    public function revertir(Ejecucion $ejecucion, int $i): RedirectResponse
    {
        $item = $ejecucion->items[$i] ?? null;
        abort_unless($item && $ejecucion->terminada(), 404);
        if (empty($item['backup']) || !in_array($item['estado'], ['ok', 'ok_sin_verificar', 'cambio_visual'], true)) {
            return back()->with('error', 'Este elemento no tiene una versión anterior guardada.');
        }
        $ejecucion->marcarItem($i, 'revirtiendo', null);
        RevertirItem::dispatch($ejecucion->id, $i);

        return back()->with('success', "Volviendo {$item['nombre']} a la versión {$item['desde']}…");
    }

    public function captura(Ejecucion $ejecucion, string $archivo): StreamedResponse
    {
        abort_unless(preg_match('/^\d+-(escritorio|movil)-(antes|despues|diff)\.jpg$/', $archivo), 404);
        $ruta = CapturasService::ruta($ejecucion->id, $archivo);
        abort_unless(Storage::disk('local')->exists($ruta), 404);

        return Storage::disk('local')->response($ruta);
    }
}
