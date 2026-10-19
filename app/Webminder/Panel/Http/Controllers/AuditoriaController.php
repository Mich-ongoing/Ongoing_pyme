<?php

namespace App\Webminder\Panel\Http\Controllers;

use App\Webminder\Panel\Models\EventoAuditoria;
use App\Webminder\Panel\Models\SitioWeb;
use App\Webminder\Panel\Models\Cliente;
use Illuminate\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AuditoriaController
{
    /**
     * Listar eventos de auditoría
     */
    public function index(Request $request): View
    {
        $query = EventoAuditoria::query();

        // Filtrar por acción
        if ($request->filled('accion')) {
            $query->where('accion', $request->string('accion'));
        }

        // Filtrar por resultado
        if ($request->filled('resultado')) {
            $query->where('resultado', $request->string('resultado'));
        }

        // Filtrar por sitio
        if ($request->filled('sitio_id')) {
            $query->where('sitio_id', $request->integer('sitio_id'));
        }

        // Filtrar por cliente
        if ($request->filled('cliente_id')) {
            $query->where('cliente_id', $request->integer('cliente_id'));
        }

        // Filtrar por rango de fechas
        if ($request->filled('fecha_desde')) {
            $query->whereDate('created_at', '>=', $request->date('fecha_desde'));
        }

        if ($request->filled('fecha_hasta')) {
            $query->whereDate('created_at', '<=', $request->date('fecha_hasta'));
        }

        $eventos = $query->latest()
            ->with('cliente', 'sitio')
            ->paginate(50);

        $acciones = array_keys(EventoAuditoria::ACCIONES);
        $clientes = Cliente::pluck('nombre', 'id');
        $sitios = SitioWeb::pluck('nombre', 'id');

        return view('webminder-panel::auditoria.index', [
            'eventos' => $eventos,
            'acciones' => $acciones,
            'clientes' => $clientes,
            'sitios' => $sitios,
        ]);
    }

    /**
     * Ver detalle de evento
     */
    public function show(EventoAuditoria $evento): View
    {
        return view('webminder-panel::auditoria.show', [
            'evento' => $evento,
        ]);
    }

    /**
     * Estadísticas de eventos
     */
    public function estadisticas(): JsonResponse
    {
        $por_accion = EventoAuditoria::selectRaw('accion, COUNT(*) as total')
            ->groupBy('accion')
            ->get()
            ->mapWithKeys(fn($item) => [
                EventoAuditoria::getAccionLabel($item->accion) => $item->total
            ]);

        $por_resultado = EventoAuditoria::selectRaw('resultado, COUNT(*) as total')
            ->groupBy('resultado')
            ->get()
            ->mapWithKeys(fn($item) => [$item->resultado => $item->total]);

        $ultimos_7_dias = EventoAuditoria::selectRaw('DATE(created_at) as fecha, COUNT(*) as total')
            ->whereDate('created_at', '>=', now()->subDays(7))
            ->groupBy('fecha')
            ->orderBy('fecha')
            ->get();

        return response()->json([
            'por_accion' => $por_accion,
            'por_resultado' => $por_resultado,
            'ultimos_7_dias' => $ultimos_7_dias,
        ]);
    }

    /**
     * Limpiar eventos antiguos
     */
    public function limpiar(Request $request): JsonResponse
    {
        $dias = $request->integer('dias', 30);

        $eliminados = EventoAuditoria::whereDate('created_at', '<', now()->subDays($dias))
            ->delete();

        return response()->json([
            'success' => true,
            'eliminados' => $eliminados,
        ]);
    }
}
