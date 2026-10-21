<?php

namespace App\Webminder\Panel\Jobs;

use App\Webminder\Panel\Models\SitioWeb;
use App\Webminder\Panel\Services\AvisosService;
use App\Webminder\Panel\Services\CerrojoService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** «Actualizar Cerrojo en todas»: un sitio por trabajo; si alguno falla, se avisa por correo. */
class InstalarCerrojo implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $sitioId)
    {
    }

    public function handle(CerrojoService $cerrojo): void
    {
        $sitio = SitioWeb::with('credencial')->findOrFail($this->sitioId);
        $r = $cerrojo->instalar($sitio);
        if (!$r['success']) {
            AvisosService::enviar("Cerrojo no se actualizó en {$sitio->nombre}", "{$sitio->url}: " . ($r['error'] ?? 'error desconocido'));
        }
    }
}
