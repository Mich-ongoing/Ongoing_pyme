<?php

namespace App\Webminder\Panel\Http\Controllers;

use App\Webminder\Panel\Models\InjertoSolicitud;
use App\Webminder\Panel\Models\SitioWeb;
use App\Webminder\Panel\Services\InjertoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InjertoController
{
    public function __construct(protected InjertoService $injerto)
    {
    }

    public function index(): View
    {
        return view('webminder-panel::injerto.index', [
            'pendientes' => InjertoSolicitud::with('sitio')->where('estado', 'pendiente')->latest()->get(),
            'recientes' => InjertoSolicitud::with('sitio', 'decisor')->where('estado', '!=', 'pendiente')->latest('updated_at')->limit(20)->get(),
            'avisados' => SitioWeb::where('tiene_injerto', true)->get()->filter(fn ($s) => !empty($s->dominios_scripts['avisados'])),
            'vigilados' => SitioWeb::where('tiene_injerto', true)->orderBy('nombre')->get(),
        ]);
    }

    public function show(InjertoSolicitud $solicitud): View
    {
        return view('webminder-panel::injerto.show', [
            'solicitud' => $solicitud->load('sitio', 'decisor'),
            'resumen' => InjertoService::resumen($solicitud->valores, $solicitud->anteriores),
        ]);
    }

    public function aprobar(Request $request, InjertoSolicitud $solicitud): RedirectResponse
    {
        abort_unless($solicitud->estado === 'pendiente', 409, 'Esta solicitud ya se decidió.');

        return $this->injerto->aprobar($solicitud, $request->user())
            ? back()->with('success', 'Aprobado: el código ya se publica en la web.')
            : back()->with('error', 'La web no aceptó la publicación: ' . $solicitud->error);
    }

    public function rechazar(Request $request, InjertoSolicitud $solicitud): RedirectResponse
    {
        abort_unless($solicitud->estado === 'pendiente', 409, 'Esta solicitud ya se decidió.');
        $this->injerto->rechazar($solicitud, $request->user());

        return back()->with('success', 'Rechazado: no se publica nada.');
    }

    /** Aceptar un dominio avisado (ya no avisa) u olvidar el aviso (avisará si vuelve). */
    public function dominio(Request $request, SitioWeb $sitio): RedirectResponse
    {
        $datos = $request->validate(['dominio' => ['required', 'string', 'max:255'], 'accion' => ['required', 'in:aceptar,olvidar']]);
        $datos['accion'] === 'aceptar'
            ? $this->injerto->conocer($sitio, [$datos['dominio']])
            : $this->injerto->olvidar($sitio, $datos['dominio']);

        return back()->with('success', $datos['accion'] === 'aceptar' ? "{$datos['dominio']} aceptado en {$sitio->nombre}." : "Aviso de {$datos['dominio']} olvidado.");
    }
}
