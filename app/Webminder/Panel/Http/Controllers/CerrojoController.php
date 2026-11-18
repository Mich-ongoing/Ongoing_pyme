<?php

namespace App\Webminder\Panel\Http\Controllers;

use App\Webminder\Panel\Jobs\InstalarCerrojo;
use App\Webminder\Panel\Models\SitioWeb;
use App\Webminder\Panel\Services\AgenteComunicacionService;
use App\Webminder\Panel\Services\AvisosService;
use App\Webminder\Panel\Services\CerrojoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CerrojoController
{
    public function __construct(protected CerrojoService $cerrojo)
    {
    }

    public function index(): View
    {
        return view('webminder-panel::cerrojo.index', [
            'sitios' => SitioWeb::where('activo', true)->orderBy('nombre')->get(),
            'version' => CerrojoService::version(),
        ]);
    }

    public function instalar(SitioWeb $sitio): RedirectResponse
    {
        return $this->responder(fn () => $this->cerrojo->instalar($sitio), "Cerrojo actualizado en {$sitio->nombre}.");
    }

    /** Instala la versión del panel en las webs que no la tienen (o tienen una anterior), en cola. */
    public function instalarTodos(): RedirectResponse
    {
        $version = CerrojoService::version();
        if (!$version) {
            return back()->with('error', 'Falta el archivo de Cerrojo en el panel: ' . config('services.cerrojo.archivo'));
        }
        $n = 0;
        foreach (SitioWeb::where('activo', true)->get() as $sitio) {
            $datos = $sitio->estadoActual()?->datos ?? [];
            $estado = $datos['cerrojo'] ?? null;
            // Solo el worker 2.5+ sabe instalar Cerrojo; sin estado no sabemos ni si contesta
            if (version_compare((string) ($datos['worker_version'] ?? '0'), '2.5.0', '>=') && $estado !== null
                && (!($estado['installed'] ?? false) || version_compare((string) ($estado['version'] ?? '0'), $version, '<'))) {
                InstalarCerrojo::dispatch($sitio->id);
                $n++;
            }
        }

        return back()->with('success', $n ? "Actualizando Cerrojo a la {$version} en {$n} webs. Si alguna falla, llega un aviso por correo." : "Todas las webs ya tienen Cerrojo {$version}.");
    }

    public function comentarios(Request $request, SitioWeb $sitio, AgenteComunicacionService $agente): RedirectResponse
    {
        $cerrar = $request->boolean('cerrar');
        $r = $agente->comentarios($sitio, $cerrar);
        if ($r['success']) {
            $agente->obtenerStatus($sitio);
        }

        return $r['success'] ? back()->with('success', 'Comentarios ' . ($cerrar ? 'cerrados' : 'abiertos') . " en {$sitio->nombre}.")
            : back()->with('error', 'La web no lo aceptó: ' . ($r['error'] ?? 'error desconocido'));
    }

    /** Cierra los comentarios en todas las webs con worker 2.12+ que aún los tengan abiertos, en cola. */
    public function comentariosTodos(): RedirectResponse
    {
        $n = 0;
        foreach (SitioWeb::where('activo', true)->get() as $sitio) {
            $datos = $sitio->estadoActual()?->datos ?? [];
            if (version_compare((string) ($datos['worker_version'] ?? '0'), '2.12.0', '>=') && empty($datos['comentarios_cerrados'])) {
                $id = $sitio->id;
                dispatch(function (AgenteComunicacionService $agente) use ($id) {
                    $sitio = SitioWeb::with('credencial')->findOrFail($id);
                    $r = $agente->comentarios($sitio, true);
                    $r['success'] ? $agente->obtenerStatus($sitio)
                        : AvisosService::enviar("No se cerraron los comentarios en {$sitio->nombre}", "{$sitio->url}: " . ($r['error'] ?? 'error desconocido'));
                });
                $n++;
            }
        }

        return back()->with('success', $n ? "Cerrando los comentarios en {$n} webs. Si alguna falla, llega un aviso por correo." : 'No queda ninguna web con worker 2.12 con los comentarios abiertos.');
    }

    public function permitir(Request $request, SitioWeb $sitio): RedirectResponse
    {
        $minutos = (int) $request->validate(['minutos' => ['required', 'in:30,60,120']])['minutos'];

        return $this->responder(fn () => $this->cerrojo->permitir($sitio, $minutos), "Instalación de plugins abierta {$minutos} min en {$sitio->nombre}. Se cierra sola.");
    }

    public function cerrar(SitioWeb $sitio): RedirectResponse
    {
        return $this->responder(fn () => $this->cerrojo->cerrar($sitio), "Instalación de plugins cerrada en {$sitio->nombre}.");
    }

    public function limpiar(SitioWeb $sitio): RedirectResponse
    {
        $r = $this->cerrojo->limpiar($sitio);
        if (!$r['success']) {
            return back()->with('error', 'No se pudo limpiar: ' . ($r['error'] ?? 'error desconocido'));
        }

        return back()->with('success', count($r['hechos'] ?? []) . " cosas limpiadas en {$sitio->nombre}; quedan " . count($r['findings'] ?? []) . ' por revisar a mano.')
            ->with('hallazgos', array_merge($r['hechos'] ?? [], $r['findings'] ?? []));
    }

    public function usuarios(SitioWeb $sitio): View
    {
        $r = $this->cerrojo->usuarios($sitio);

        return view('webminder-panel::cerrojo.usuarios', [
            'sitio' => $sitio,
            'usuarios' => $r['success'] ? $r['usuarios'] : null,
            'fallo' => $r['success'] ? null : ($r['error'] ?? 'error desconocido'),
        ]);
    }

    public function desbloquear(Request $request, SitioWeb $sitio): RedirectResponse
    {
        $login = $request->validate(['login' => ['required', 'string', 'max:60']])['login'];

        return $this->responder(fn () => $this->cerrojo->desbloquear($sitio, $login), "«{$login}» desbloqueado: ya puede entrar con su contraseña.");
    }

    public function autorizarAdmin(Request $request, SitioWeb $sitio): RedirectResponse
    {
        $login = $request->validate(['login' => ['required', 'string', 'max:60']])['login'];

        return $this->responder(fn () => $this->cerrojo->autorizarAdmin($sitio, $login), "«{$login}» es administrador de confianza: Cerrojo ya no lo degrada.");
    }

    private function responder(callable $accion, string $hecho): RedirectResponse
    {
        try {
            $r = $accion();
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage()); // falta la clave o el archivo en el panel
        }

        return $r['success'] ? back()->with('success', $hecho) : back()->with('error', 'La web no lo aceptó: ' . ($r['error'] ?? 'error desconocido'));
    }
}
