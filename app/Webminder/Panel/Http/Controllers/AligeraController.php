<?php

namespace App\Webminder\Panel\Http\Controllers;

use App\Webminder\Panel\Models\AligeraActivacion;
use App\Webminder\Panel\Models\AligeraLicencia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AligeraController
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));
        $manifiesto = storage_path('app/aligera/manifest.json');

        return view('webminder-panel::aligera.index', [
            'licencias' => AligeraLicencia::with('activaciones')
                ->when($q !== '', fn ($c) => $c->where(fn ($c) => $c->where('email', 'like', "%{$q}%")->orWhere('clave', 'like', "%{$q}%")
                    ->orWhereHas('activaciones', fn ($a) => $a->where('dominio', 'like', "%{$q}%"))))
                ->latest()->get(),
            'q' => $q,
            'version' => is_file($manifiesto) ? json_decode(file_get_contents($manifiesto), true)['version'] ?? null : null,
        ]);
    }

    /** Licencia sin pasar por Stripe: webs propias, regalos o una venta por transferencia. */
    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'email' => ['required', 'email'],
            'plan' => ['required', 'in:' . implode(',', array_keys(AligeraLicencia::PLANES))],
            'meses' => ['required', 'integer', 'min:1', 'max:120'],
            'nota' => ['nullable', 'string', 'max:255'],
        ]);
        $licencia = AligeraLicencia::nueva($datos['email'], $datos['plan'], now()->addMonths((int) $datos['meses']), ['nota' => $datos['nota'] ?? null]);
        $enviada = $request->boolean('enviar') ? $licencia->enviarClave() : null;

        return back()->with('success', "Licencia {$licencia->clave} creada" . match ($enviada) {
            true => " y enviada a {$licencia->email}.", false => ', pero NO se pudo enviar el correo.', null => '.',
        });
    }

    public function reenviar(AligeraLicencia $licencia): RedirectResponse
    {
        return $licencia->enviarClave()
            ? back()->with('success', "Clave reenviada a {$licencia->email}.")
            : back()->with('error', 'No se pudo enviar el correo (mira storage/logs).');
    }

    /** Deja de recibir actualizaciones ya. No cancela la suscripción: eso se hace en Stripe. */
    public function anular(AligeraLicencia $licencia): RedirectResponse
    {
        $licencia->update(['caduca_en' => now()]);

        return back()->with('success', "Licencia {$licencia->clave} anulada." . ($licencia->stripe_suscripcion ? ' Cancela también la suscripción en Stripe.' : ''));
    }

    /** Libera el hueco de una web que ya no existe (si existe, lo normal es desactivar desde su WordPress). */
    public function liberar(AligeraActivacion $activacion): RedirectResponse
    {
        $activacion->delete();

        return back()->with('success', "{$activacion->dominio} liberada.");
    }
}
