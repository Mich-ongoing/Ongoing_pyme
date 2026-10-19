<?php

namespace App\Webminder\Panel\Http\Controllers;

use App\Webminder\Panel\Models\SitioWeb;
use App\Webminder\Panel\Services\AgenteComunicacionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class OperacionesController
{
    protected AgenteComunicacionService $agente;

    public function __construct(AgenteComunicacionService $agente)
    {
        $this->agente = $agente;
    }

    /**
     * Activar mantenimiento
     */
    public function activarMantenimiento(SitioWeb $sitio): RedirectResponse
    {
        $resultado = $this->agente->activarMantenimiento($sitio);

        if ($resultado['success'] ?? false) {
            $sitio->update(['estado' => 'mantenimiento']);
            $this->agente->obtenerStatus($sitio);

            return back()->with('success', 'Modo mantenimiento activado correctamente en el sitio.');
        }

        return back()->with('error', 'Error al activar modo mantenimiento: ' . ($resultado['error'] ?? 'Respuesta inválida'));
    }

    /**
     * Desactivar mantenimiento
     */
    public function desactivarMantenimiento(SitioWeb $sitio): RedirectResponse
    {
        $resultado = $this->agente->desactivarMantenimiento($sitio);

        if ($resultado['success'] ?? false) {
            $sitio->update(['estado' => 'online']);
            $this->agente->obtenerStatus($sitio);

            return back()->with('success', 'Modo mantenimiento desactivado correctamente.');
        }

        return back()->with('error', 'Error al desactivar modo mantenimiento: ' . ($resultado['error'] ?? 'Respuesta inválida'));
    }

    /**
     * Limpiar BD
     */
    public function limpiarBD(Request $request, SitioWeb $sitio): JsonResponse
    {
        $opciones = $request->validate([
            'clean_revisions' => 'boolean',
            'clean_spam' => 'boolean',
            'clean_orphaned_meta' => 'boolean',
            'clean_trash' => 'boolean',
            'optimize_tables' => 'boolean',
        ]);

        $resultado = $this->agente->limpiarBD($sitio, $opciones);

        return response()->json($resultado);
    }

    /**
     * Obtener actualizaciones
     */
    public function actualizaciones(SitioWeb $sitio): JsonResponse
    {
        $resultado = $this->agente->obtenerActualizaciones($sitio);

        return response()->json($resultado);
    }

    /**
     * Ejecutar actualización
     */
    public function actualizar(Request $request, SitioWeb $sitio): JsonResponse
    {
        $paquete = $request->string('paquete')->required();

        $resultado = $this->agente->ejecutarActualizacion($sitio, $paquete);

        return response()->json($resultado);
    }

    /**
     * Corregir seguridad
     */
    public function corregirSeguridad(Request $request, SitioWeb $sitio): JsonResponse
    {
        $problema = $request->string('problema')->required();

        $resultado = $this->agente->corregirSeguridad($sitio, $problema);

        return response()->json($resultado);
    }

    /**
     * Obtener reporte de salud completo
     */
    public function reporteSalud(SitioWeb $sitio): JsonResponse
    {
        $resultado = $this->agente->obtenerSaludCompleta($sitio);

        return response()->json($resultado);
    }
}
