<?php

namespace App\Webminder\Panel\Http\Controllers;

use App\Webminder\Panel\Models\SitioWeb;
use App\Webminder\Panel\Services\AgenteComunicacionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;

class OperacionesController
{
    // Lo que acepta el worker en /db-clean (whitelist propia allí también).
    public const ITEMS_LIMPIEZA = ['revisions', 'spam_comments', 'trashed_comments', 'transients', 'auto_drafts', 'trashed_posts', 'optimize_tables'];

    public function __construct(protected AgenteComunicacionService $agente)
    {
    }

    public function activarMantenimiento(SitioWeb $sitio): RedirectResponse
    {
        return $this->cambiarMantenimiento($sitio, true);
    }

    public function desactivarMantenimiento(SitioWeb $sitio): RedirectResponse
    {
        return $this->cambiarMantenimiento($sitio, false);
    }

    private function cambiarMantenimiento(SitioWeb $sitio, bool $activar): RedirectResponse
    {
        $resultado = $activar ? $this->agente->activarMantenimiento($sitio) : $this->agente->desactivarMantenimiento($sitio);

        if (!$resultado['success']) {
            return back()->with('error', 'No se pudo cambiar el modo mantenimiento: ' . $resultado['error']);
        }
        $this->agente->obtenerStatus($sitio);

        return back()->with('success', $activar ? 'Modo mantenimiento activado.' : 'Modo mantenimiento desactivado.');
    }

    public function limpiarBD(Request $request, SitioWeb $sitio): JsonResponse
    {
        $validado = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*' => [Rule::in(self::ITEMS_LIMPIEZA)],
        ]);

        return response()->json($this->agente->limpiarBD($sitio, $validado['items']));
    }

    public function actualizaciones(Request $request, SitioWeb $sitio): JsonResponse
    {
        return response()->json($this->agente->obtenerActualizaciones($sitio, $request->boolean('refrescar')));
    }

    public function actualizar(Request $request, SitioWeb $sitio): JsonResponse
    {
        $validado = $request->validate([
            'tipo' => ['required', Rule::in(['plugin', 'theme', 'translation'])],
            'item' => ['required', 'string', 'max:255'],
        ]);

        return response()->json($this->agente->ejecutarActualizacion($sitio, $validado['tipo'], $validado['item']));
    }

    public function corregirSeguridad(Request $request, SitioWeb $sitio): JsonResponse
    {
        $validado = $request->validate(['problema' => ['required', Rule::in(['file_edit'])]]);

        return response()->json($this->agente->corregirSeguridad($sitio, $validado['problema']));
    }

    public function reporteSalud(SitioWeb $sitio): JsonResponse
    {
        return response()->json($this->agente->obtenerSaludCompleta($sitio));
    }

    /**
     * Revisión completa de Cerrojo en el sitio (solo lectura). Puede tardar minutos en sitios grandes.
     */
    public function revisionSeguridad(SitioWeb $sitio): RedirectResponse
    {
        $resultado = $this->agente->peticionLarga($sitio, 'security-scan', [], 600);

        if (!$resultado['success']) {
            return back()->with('error', 'No se pudo revisar: ' . $resultado['error']);
        }
        $this->agente->obtenerStatus($sitio);
        $hallazgos = $resultado['findings'] ?? [];

        return back()
            ->with($hallazgos ? 'error' : 'success', $hallazgos ? count($hallazgos) . ' hallazgos de Cerrojo.' : 'Cerrojo no encontró nada.')
            ->with('hallazgos', $hallazgos);
    }

    /**
     * Acceso 1-clic al wp-admin con la cuenta de la agencia.
     */
    public function sso(SitioWeb $sitio): RedirectResponse
    {
        $resultado = $this->agente->sso($sitio);

        if (!$resultado['success'] || empty($resultado['login_url'])) {
            return back()->with('error', 'No se pudo abrir el acceso: ' . ($resultado['error'] ?? 'sin enlace'));
        }

        return redirect()->away($resultado['login_url']);
    }
}
