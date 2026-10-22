<?php

namespace App\Webminder\Panel\Http\Controllers;

use App\Webminder\Panel\Jobs\ActualizarSitio;
use App\Webminder\Panel\Models\Ejecucion;
use App\Webminder\Panel\Models\SitioWeb;
use App\Webminder\Panel\Models\Credencial;
use App\Webminder\Panel\Models\EventoActividad;
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
            'actividad' => EventoActividad::where('sitio_id', $sitio->id)->latest('ocurrido_en')->limit(30)->get(),
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
        // obtenerStatus ya guarda online/mantenimiento/offline
        $resultado = $this->agente->obtenerStatus($sitio);

        return $resultado['success']
            ? back()->with('success', 'El estado del sitio web ha sido verificado y sincronizado correctamente.')
            : back()->with('error', 'No se pudo conectar con el sitio: ' . $resultado['error']);
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

        // Sitio sin vincular (o desvinculado): credenciales nuevas, a pegar en el worker.
        if (!$credencial?->activa || !$credencial->ultima_utilizacion) {
            Credencial::updateOrCreate(['sitio_id' => $sitio->id], [
                'client_id' => Credencial::generarClientId($sitio->id),
                'client_secret' => Credencial::generarClientSecret(),
                'activa' => true,
                'ultima_utilizacion' => null,
            ]);

            return redirect()->route('webminder-panel.sitios.credenciales', $sitio)
                ->with('success', 'Credenciales nuevas generadas: pega el token de enlace en el worker.');
        }

        // Vinculado: rotar en ambos lados sin romper la conexión.
        $resultado = $this->agente->rotarSecreto($sitio);

        return $resultado['success']
            ? back()->with('success', $resultado['message'])
            : back()->with('error', $resultado['error']);
    }

    /** Manda un correo de prueba desde la web al usuario del panel y refresca el estado para ver el resultado. */
    public function probarCorreo(Request $request, SitioWeb $sitio): RedirectResponse
    {
        $para = $request->user()->email;
        $resultado = $this->agente->probarCorreo($sitio, $para);
        $this->agente->obtenerStatus($sitio);

        return back()->with(
            $resultado['success'] ? 'success' : 'error',
            $resultado['success']
                ? "La web aceptó el envío a {$para}. Mira que te llega (y que no está en spam): si llega, los formularios también."
                : 'La web no pudo enviar el correo: ' . $resultado['error']
        );
    }

    /** Cierra la sesión de todos los usuarios de la web (forzar_clave además les exige contraseña nueva; claves renueva las de wp-config.php). */
    public function sesiones(Request $request, SitioWeb $sitio): RedirectResponse
    {
        $accion = $request->validate(['accion' => ['required', 'in:cerrar,forzar_clave,claves']])['accion'];
        $resultado = $this->agente->sesiones($sitio, $accion);

        return back()->with($resultado['success'] ? 'success' : 'error', $resultado['success'] ? $resultado['message'] : 'La web no lo aplicó: ' . $resultado['error']);
    }

    /** Lote que reinstala la misma versión de cada plugin de wordpress.org (copia, comprobación y vuelta atrás como una actualización). */
    public function reinstalarPlugins(Request $request, SitioWeb $sitio): RedirectResponse
    {
        if (Ejecucion::where('sitio_id', $sitio->id)->whereIn('estado', ['pendiente', 'en_curso'])->exists()) {
            return back()->with('error', 'Ya hay un lote en curso en esta web: espera a que termine.');
        }
        $resultado = $this->agente->obtenerStatus($sitio);
        if (!$resultado['success']) {
            return back()->with('error', 'No se pudo leer la lista de plugins: ' . $resultado['error']);
        }
        $items = collect($resultado['data']['plugins_list'] ?? [])->where('wporg', true)->map(fn ($p) => [
            'tipo' => 'plugin', 'item' => $p['file'], 'nombre' => $p['name'], 'desde' => $p['version'], 'hasta' => $p['version'],
            'estado' => 'pendiente', 'mensaje' => null, 'reinstalar' => true,
        ])->values()->all();
        if (!$items) {
            return back()->with('error', 'Esta web no tiene plugins gratuitos de wordpress.org que reinstalar.');
        }
        $ejecucion = Ejecucion::create(['sitio_id' => $sitio->id, 'usuario_id' => $request->user()?->id, 'copia_bd' => false, 'items' => $items]);
        ActualizarSitio::dispatch($ejecucion->id);

        return redirect()->route('webminder-panel.ejecuciones.show', $ejecucion);
    }

    public function desconectar(SitioWeb $sitio): RedirectResponse
    {
        $resultado = $this->agente->desconectar($sitio);

        return back()->with(
            $resultado['success'] ? 'success' : 'error',
            $resultado['success'] ? 'Sitio desvinculado: el worker ha borrado sus credenciales.' : 'El panel ya no usará estas credenciales, pero el sitio no confirmó: ' . $resultado['error']
        );
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
