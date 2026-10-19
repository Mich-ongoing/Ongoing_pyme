<?php

namespace App\Webminder\Panel\Http\Controllers;

use App\Webminder\Panel\Jobs\ActualizarSitio;
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

        $items = [];
        foreach ($validado['items'] as $clave) {
            if ($u = $pendientes->get($clave)) {
                $items[] = [
                    'tipo' => $u['type'], 'item' => $u['item'], 'nombre' => $u['name'] ?? $u['item'],
                    'desde' => $u['current_version'] ?? null, 'hasta' => $u['version'] ?? null,
                    'estado' => 'pendiente', 'mensaje' => null,
                ];
            }
        }
        if (!$items) {
            return back()->with('error', 'Ninguno de los elementos elegidos sigue pendiente en el sitio.');
        }

        // Traducciones al final: si algo se revierte, que no hayan cambiado ya los idiomas
        usort($items, fn ($a, $b) => ($a['tipo'] === 'translation') <=> ($b['tipo'] === 'translation'));

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
}
