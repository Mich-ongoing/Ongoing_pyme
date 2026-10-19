<?php

namespace App\Webminder\Panel\Http\Controllers;

use App\Webminder\Panel\Models\Cliente;
use App\Webminder\Panel\Models\SitioWeb;
use App\Webminder\Panel\Services\AgenteComunicacionService;
use Illuminate\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;

class ClientesController
{
    /**
     * Listar clientes
     */
    public function index(): View
    {
        $clientes = Cliente::withCount('sitios')
            ->latest()
            ->paginate(20);

        return view('webminder-panel::clientes.index', [
            'clientes' => $clientes,
        ]);
    }

    /**
     * Ver detalles de cliente
     */
    public function show(Cliente $cliente): View
    {
        $sitios = $cliente->sitios()->paginate(15);
        $eventos = $cliente->eventos()->latest()->limit(20)->get();

        return view('webminder-panel::clientes.show', [
            'cliente' => $cliente,
            'sitios' => $sitios,
            'eventos' => $eventos,
        ]);
    }

    /**
     * Crear nuevo cliente
     */
    public function create(): View
    {
        return view('webminder-panel::clientes.create');
    }

    /**
     * Guardar cliente
     */
    public function store(Request $request): RedirectResponse
    {
        $validado = $request->validate([
            'nombre' => 'required|string|max:255',
            'empresa' => 'required|string|max:255',
            'email' => 'required|email|unique:panel_clientes',
            'telefono' => 'nullable|string|max:20',
            'ciudad' => 'nullable|string|max:100',
            'pais' => 'nullable|string|max:100',
        ]);

        Cliente::create([
            ...$validado,
            'activo' => true,
        ]);

        return redirect()->route('webminder-panel.clientes.index')
            ->with('success', 'Cliente creado correctamente');
    }

    /**
     * Editar cliente
     */
    public function edit(Cliente $cliente): View
    {
        return view('webminder-panel::clientes.edit', [
            'cliente' => $cliente,
        ]);
    }

    /**
     * Actualizar cliente
     */
    public function update(Request $request, Cliente $cliente): RedirectResponse
    {
        $validado = $request->validate([
            'nombre' => 'required|string|max:255',
            'empresa' => 'required|string|max:255',
            'email' => 'required|email|unique:panel_clientes,email,' . $cliente->id,
            'telefono' => 'nullable|string|max:20',
            'ciudad' => 'nullable|string|max:100',
            'pais' => 'nullable|string|max:100',
            'activo' => 'boolean',
        ]);

        $cliente->update($validado);

        return redirect()->route('webminder-panel.clientes.show', $cliente)
            ->with('success', 'Cliente actualizado correctamente');
    }

    /**
     * Desactivar cliente
     */
    public function desactivar(Cliente $cliente): RedirectResponse
    {
        $cliente->update(['activo' => false]);

        return back()->with('success', 'Cliente desactivado');
    }

    /**
     * Activar cliente
     */
    public function activar(Cliente $cliente): RedirectResponse
    {
        $cliente->update(['activo' => true]);

        return back()->with('success', 'Cliente activado');
    }

    /**
     * API: Estadísticas del cliente
     */
    public function estadisticas(Cliente $cliente): JsonResponse
    {
        return response()->json([
            'cliente_id' => $cliente->id,
            'nombre' => $cliente->nombre,
            'sitios_totales' => $cliente->sitios()->count(),
            'sitios_activos' => $cliente->sitiosActivos(),
            'sitios_online' => $cliente->sitios()->where('estado', 'online')->count(),
            'sitios_offline' => $cliente->sitios()->where('estado', 'offline')->count(),
            'ultima_sincronizacion' => $cliente->ultimaSincronizacion(),
        ]);
    }
}
