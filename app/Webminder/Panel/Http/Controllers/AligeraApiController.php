<?php

namespace App\Webminder\Panel\Http\Controllers;

use App\Webminder\Panel\Models\AligeraLicencia;
use App\Webminder\Panel\Services\AvisosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Licencias de Aligera Pro: el webhook de Stripe las crea y renueva; el plugin las activa
 * y pregunta por actualizaciones. Sin sesión ni CSRF.
 */
class AligeraApiController
{
    private const TOLERANCIA_STRIPE = 300;

    public function stripe(Request $request): JsonResponse
    {
        if (!$this->firmaStripeValida($request)) {
            return response()->json(['error' => 'Firma inválida'], 400);
        }
        $evento = $request->json()->all();
        $o = $evento['data']['object'] ?? [];

        match ($evento['type'] ?? '') {
            'checkout.session.completed' => $this->compra($o),
            'invoice.paid' => $this->renovacion($o),
            'customer.subscription.updated' => $this->cambioDePlan($o),
            // Se acabó la suscripción (no renovó o se canceló con reembolso): la licencia caduca ya
            'customer.subscription.deleted' => AligeraLicencia::where('stripe_suscripcion', $o['id'] ?? '')
                ->update(['caduca_en' => Carbon::createFromTimestamp($o['ended_at'] ?? time())]),
            default => null,
        };

        return response()->json(['ok' => true]);
    }

    private function compra(array $o): void
    {
        $suscripcion = $o['subscription'] ?? null;
        if (($o['mode'] ?? '') !== 'subscription' || !$suscripcion || AligeraLicencia::where('stripe_suscripcion', $suscripcion)->exists()) {
            return; // no es de Aligera, o Stripe reenvía un evento ya atendido
        }
        $plan = config('services.aligera.enlaces')[$o['payment_link'] ?? ''] ?? ($o['metadata']['plan'] ?? null);
        $email = $o['customer_details']['email'] ?? null;
        if (!isset(AligeraLicencia::PLANES[$plan]) || !$email) {
            Log::warning('Aligera: pago sin plan o sin email', ['sesion' => $o['id'] ?? null, 'enlace' => $o['payment_link'] ?? null]);
            AvisosService::enviar('Aligera: pago sin licencia', "Stripe avisó de un pago ({$suscripcion}) pero no se sabe el plan o el email. Revisa ALIGERA_STRIPE_ENLACES y crea la licencia a mano.");

            return;
        }
        $licencia = AligeraLicencia::nueva($email, $plan, now()->addYear()->addDays(AligeraLicencia::GRACIA_DIAS), [
            'stripe_suscripcion' => $suscripcion,
            'stripe_cliente' => $o['customer'] ?? null,
        ]);
        $enviada = $licencia->enviarClave();
        AvisosService::enviar('Aligera: venta nueva', "Plan " . AligeraLicencia::PLANES[$plan][0] . " para {$email}.\nClave: {$licencia->clave}"
            . ($enviada ? '' : "\n\nNO se pudo enviar la clave al cliente: reenvíala desde el panel."));
    }

    private function renovacion(array $o): void
    {
        // La ruta del id de suscripción cambió en la API de Stripe 2025-03 (parent.subscription_details)
        $suscripcion = $o['subscription'] ?? $o['parent']['subscription_details']['subscription'] ?? null;
        $fin = collect($o['lines']['data'] ?? [])->max(fn ($l) => $l['period']['end'] ?? 0);
        $licencia = $suscripcion ? AligeraLicencia::where('stripe_suscripcion', $suscripcion)->first() : null;
        if (!$licencia || !$fin) {
            return; // la primera factura puede llegar antes que la compra: la compra ya pone un año
        }
        $caduca = Carbon::createFromTimestamp($fin)->addDays(AligeraLicencia::GRACIA_DIAS);
        if ($caduca->gt($licencia->caduca_en)) {
            $licencia->update(['caduca_en' => $caduca]);
        }
    }

    /** Cambio de plan desde el portal de Stripe: Stripe cobra la diferencia; aquí solo cambian el plan y las webs. */
    private function cambioDePlan(array $o): void
    {
        $plan = config('services.aligera.precios')[$o['items']['data'][0]['price']['id'] ?? ''] ?? null;
        $licencia = AligeraLicencia::where('stripe_suscripcion', $o['id'] ?? '')->first();
        if (!$licencia || !isset(AligeraLicencia::PLANES[$plan]) || $plan === $licencia->plan) {
            return; // este evento llega también por renovaciones y cambios de tarjeta
        }
        $antes = AligeraLicencia::PLANES[$licencia->plan][0];
        $licencia->update(['plan' => $plan, 'sitios_max' => AligeraLicencia::PLANES[$plan][1]]);
        $usadas = $licencia->activaciones()->count();
        // Si baja de plan con más webs activas, no se desactiva ninguna: solo no podrá activar más
        AvisosService::enviar('Aligera: cambio de plan', "{$licencia->email} pasa de {$antes} a " . AligeraLicencia::PLANES[$plan][0] . " ({$licencia->clave})."
            . ($usadas > $licencia->sitios_max ? "\nTiene {$usadas} webs activas y el plan nuevo admite {$licencia->sitios_max}: no podrá activar más hasta liberar alguna." : ''));
    }

    private function firmaStripeValida(Request $request): bool
    {
        $secreto = (string) config('services.aligera.stripe_webhook');
        $partes = [];
        foreach (explode(',', (string) $request->header('Stripe-Signature')) as $p) {
            [$k, $v] = array_pad(explode('=', trim($p), 2), 2, '');
            $partes[$k][] = $v;
        }
        $t = (int) ($partes['t'][0] ?? 0);
        if ($secreto === '' || abs(time() - $t) > self::TOLERANCIA_STRIPE) {
            return false;
        }
        $esperada = hash_hmac('sha256', $t . '.' . $request->getContent(), $secreto);
        foreach ($partes['v1'] ?? [] as $firma) {
            if (hash_equals($esperada, $firma)) {
                return true;
            }
        }

        return false;
    }

    public function activar(Request $request): JsonResponse
    {
        [$licencia, $dominio] = $this->licenciaYDominio($request);
        if (!$licencia) {
            return response()->json(['ok' => false, 'error' => 'La clave no existe.'], 404);
        }
        if (!$licencia->vigente()) {
            return response()->json(['ok' => false, 'error' => 'La licencia caducó el ' . $licencia->caduca_en->format('d/m/Y') . '.'], 403);
        }
        $activacion = $licencia->activaciones()->where('dominio', $dominio)->first();
        if (!$activacion && $licencia->activaciones()->count() >= $licencia->sitios_max) {
            return response()->json(['ok' => false, 'error' => "Esta licencia ya está activada en {$licencia->sitios_max} web(s). Desactívala en otra o pasa a un plan mayor."], 403);
        }
        ($activacion ?? $licencia->activaciones()->make(['dominio' => $dominio]))
            ->fill(['version' => $request->input('version'), 'visto_en' => now()])->save();

        return response()->json(['ok' => true] + $this->estado($licencia));
    }

    public function desactivar(Request $request): JsonResponse
    {
        [$licencia, $dominio] = $this->licenciaYDominio($request);
        $licencia?->activaciones()->where('dominio', $dominio)->delete();

        return response()->json(['ok' => true]);
    }

    /** Lo que pregunta WordPress al buscar actualizaciones; el paquete solo va si la licencia vale en esa web. */
    public function actualizacion(Request $request): JsonResponse
    {
        $ruta = storage_path('app/aligera/manifest.json');
        $manifiesto = is_file($ruta) ? json_decode(file_get_contents($ruta), true) : [];
        [$licencia, $dominio] = $this->licenciaYDominio($request);
        $activacion = $licencia?->activaciones()->where('dominio', $dominio)->first();
        $activacion?->update(['version' => $request->input('version'), 'visto_en' => now()]);

        $estado = match (true) {
            !$licencia => ['licencia' => 'no_existe'],
            !$activacion => ['licencia' => 'no_activada'] + $this->estado($licencia),
            default => $this->estado($licencia),
        };
        if ($estado['licencia'] === 'activa' && $manifiesto) {
            $manifiesto['download_url'] = route('aligera.descargar', $licencia->clave);
        }

        return response()->json($estado + $manifiesto);
    }

    public function descargar(string $clave): BinaryFileResponse
    {
        $licencia = AligeraLicencia::where('clave', $clave)->first();
        abort_unless($licencia?->vigente(), 403, 'Licencia caducada o inexistente.');
        $zip = storage_path('app/aligera/aligera-pro.zip');
        abort_unless(is_file($zip), 404);

        return response()->download($zip, 'aligera-pro.zip', ['Cache-Control' => 'no-store']);
    }

    private function licenciaYDominio(Request $request): array
    {
        $datos = $request->validate([
            'clave' => ['required', 'string', 'max:40'],
            'sitio' => ['required', 'string', 'max:255'],
            'version' => ['nullable', 'string', 'max:20'],
        ]);
        $dominio = AligeraLicencia::dominio($datos['sitio']);
        abort_if($dominio === '', 422, 'Web no válida.');

        return [AligeraLicencia::where('clave', strtoupper(trim($datos['clave'])))->first(), $dominio];
    }

    private function estado(AligeraLicencia $licencia): array
    {
        return [
            'licencia' => $licencia->vigente() ? 'activa' : 'caducada',
            'caduca' => $licencia->caduca_en->toDateString(),
            'plan' => AligeraLicencia::PLANES[$licencia->plan][0],
            'sitios_max' => $licencia->sitios_max,
            'sitios_usados' => $licencia->activaciones()->count(),
        ];
    }
}
