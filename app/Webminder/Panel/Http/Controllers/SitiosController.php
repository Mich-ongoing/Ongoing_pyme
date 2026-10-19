<?php

namespace App\Webminder\Panel\Http\Controllers;

use App\Webminder\Panel\Models\SitioWeb;
use App\Webminder\Panel\Models\Credencial;
use App\Webminder\Panel\Models\Cliente;
use App\Webminder\Panel\Services\AgenteComunicacionService;
use Illuminate\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;

class SitiosController
{
    protected AgenteComunicacionService $agente;

    public function __construct(AgenteComunicacionService $agente)
    {
        $this->agente = $agente;
    }

    /**
     * Listar sitios web
     */
    public function index(): View
    {
        $sitios = SitioWeb::with('cliente')
            ->latest()
            ->paginate(20);

        $estadisticas = [
            'online' => SitioWeb::where('estado', 'online')->count(),
            'offline' => SitioWeb::where('estado', 'offline')->count(),
            'sin_verificar' => SitioWeb::where('estado', 'sin_verificar')->count(),
        ];

        return view('webminder-panel::sitios.index', [
            'sitios' => $sitios,
            'estadisticas' => $estadisticas,
        ]);
    }

    /**
     * Ver detalles de sitio
     */
    public function show(SitioWeb $sitio): View
    {
        $estado_actual = $sitio->estadoActual();
        $eventos = $sitio->eventos()->latest()->limit(30)->get();

        return view('webminder-panel::sitios.show', [
            'sitio' => $sitio,
            'estado_actual' => $estado_actual,
            'eventos' => $eventos,
        ]);
    }

    /**
     * Crear nuevo sitio
     */
    public function create(): View
    {
        $clientes = Cliente::where('activo', true)->get();

        return view('webminder-panel::sitios.create', [
            'clientes' => $clientes,
        ]);
    }

    /**
     * Guardar nuevo sitio
     */
    public function store(Request $request): RedirectResponse
    {
        $validado = $request->validate([
            'cliente_id' => 'required|exists:panel_clientes,id',
            'nombre' => 'required|string|max:255',
            'url' => 'required|url|unique:panel_sitios_web',
            'descripcion' => 'nullable|string',
            'tecnologia' => 'nullable|string',
        ]);

        $sitio = SitioWeb::create([
            ...$validado,
            'activo' => true,
            'estado' => 'sin_verificar',
        ]);

        // Crear credenciales automáticamente
        Credencial::create([
            'sitio_id' => $sitio->id,
            'client_id' => Credencial::generarClientId($sitio->id),
            'client_secret' => Credencial::generarClientSecret(),
            'activa' => true,
        ]);

        return redirect()->route('webminder-panel.sitios.show', $sitio)
            ->with('success', 'Sitio creado. Credenciales generadas automáticamente.');
    }

    /**
     * Verificar estado del sitio (ping + status)
     */
    public function verificar(SitioWeb $sitio): RedirectResponse
    {
        $resultado = $this->agente->obtenerStatus($sitio);

        if ($resultado['success'] ?? false) {
            $sitio->update([
                'estado' => 'online',
                'ultima_verificacion' => now(),
            ]);

            return back()->with('success', 'El estado del sitio web ha sido verificado y sincronizado correctamente.');
        } else {
            $sitio->update(['estado' => 'offline']);

            $errorMsg = $resultado['error'] ?? 'El sitio no responde';

            return back()->with('error', 'No se pudo conectar con el sitio: ' . $errorMsg);
        }
    }

    /**
     * Obtener estado completo
     */
    public function estado(SitioWeb $sitio): JsonResponse
    {
        $resultado = $this->agente->obtenerStatus($sitio);

        return response()->json($resultado);
    }

    /**
     * Obtener salud de BD
     */
    public function saludBD(SitioWeb $sitio): JsonResponse
    {
        $resultado = $this->agente->obtenerSaludBD($sitio);

        return response()->json($resultado);
    }

    /**
     * Obtener paquetes
     */
    public function paquetes(SitioWeb $sitio): JsonResponse
    {
        $resultado = $this->agente->obtenerPaquetes($sitio);

        return response()->json($resultado);
    }

    /**
     * Regenerar credenciales
     */
    public function regenerarCredenciales(SitioWeb $sitio): RedirectResponse
    {
        $credencial = $sitio->credencial;

        if ($credencial) {
            $credencial->update([
                'client_id' => Credencial::generarClientId($sitio->id),
                'client_secret' => Credencial::generarClientSecret(),
            ]);
        }

        return back()->with('success', 'Credenciales regeneradas');
    }

    /**
     * Ver credenciales
     */
    public function credenciales(SitioWeb $sitio): View
    {
        $credencial = $sitio->credencial;

        return view('webminder-panel::sitios.credenciales', [
            'sitio' => $sitio,
            'credencial' => $credencial,
        ]);
    }
}
