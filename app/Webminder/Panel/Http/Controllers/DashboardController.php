<?php

namespace App\Webminder\Panel\Http\Controllers;

use App\Webminder\Panel\Models\Cliente;
use App\Webminder\Panel\Models\SitioWeb;
use App\Webminder\Panel\Models\EventoAuditoria;
use Illuminate\View\View;

class DashboardController
{
    /**
     * Mostrar dashboard principal
     */
    public function index(): View
    {
        $estadisticas = [
            'total_clientes' => Cliente::count(),
            'clientes_activos' => Cliente::where('activo', true)->count(),
            'total_sitios' => SitioWeb::count(),
            'sitios_online' => SitioWeb::where('estado', 'online')->count(),
            'sitios_offline' => SitioWeb::where('estado', 'offline')->count(),
            'sitios_en_mantenimiento' => SitioWeb::whereHas(
                'estadosHistorico',
                fn($q) => $q->where('datos->maintenance_mode', true)
            )->count(),
        ];

        $sitios_recientes = SitioWeb::with('cliente')
            ->latest('ultima_verificacion')
            ->limit(10)
            ->get();

        $eventos_recientes = EventoAuditoria::latest()
            ->limit(20)
            ->get();

        $salud_promedio = $this->calcularSaludPromedio();

        return view('webminder-panel::dashboard', [
            'estadisticas' => $estadisticas,
            'sitios_recientes' => $sitios_recientes,
            'eventos_recientes' => $eventos_recientes,
            'salud_promedio' => $salud_promedio,
        ]);
    }

    /**
     * Calcular salud promedio de todos los sitios
     */
    private function calcularSaludPromedio(): float
    {
        $sitios = SitioWeb::where('activo', true)->get();

        if ($sitios->isEmpty()) {
            return 0;
        }

        $salud_total = 0;

        foreach ($sitios as $sitio) {
            $estado = $sitio->estadoActual();
            if ($estado) {
                $salud = 0;

                // BD conectada: +25
                if ($estado->baseDatosConectada()) {
                    $salud += 25;
                }

                // Caché funcionando: +25
                if ($estado->cacheFuncionando()) {
                    $salud += 25;
                }

                // Online: +25
                if ($sitio->estaEnLinea()) {
                    $salud += 25;
                }

                // No en mantenimiento: +25
                if (!$sitio->enMantenimiento()) {
                    $salud += 25;
                }

                $salud_total += $salud;
            }
        }

        return round($salud_total / count($sitios), 2);
    }

    /**
     * Mostrar resumen rápido
     */
    public function resumen()
    {
        return response()->json([
            'timestamp' => now(),
            'estadisticas' => [
                'total_clientes' => Cliente::count(),
                'total_sitios' => SitioWeb::count(),
                'sitios_online' => SitioWeb::where('estado', 'online')->count(),
                'eventos_hoy' => EventoAuditoria::whereDate('created_at', today())->count(),
            ],
        ]);
    }
}
