<x-layouts.panel title="Clientes - Webminder Panel" headerTitle="Gestión de Clientes" headerSubtitle="Directorio general de clientes y empresas integradas">

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-slate-100">Clientes Registrados</h1>
            <p class="text-xs text-slate-400">Total: {{ $clientes->count() }} cuentas asociadas</p>
        </div>
        <flux:button href="/webminder-panel/clientes/create" variant="primary" class="bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold px-4 py-2.5 rounded-xl shadow-lg shadow-indigo-600/30">
            + Nuevo Cliente
        </flux:button>
    </div>

    <flux:card class="bg-slate-950/60 border border-slate-800 p-6 rounded-2xl">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-300">
                <thead class="bg-slate-900/80 text-xs uppercase tracking-wider text-slate-400 border-b border-slate-800">
                    <tr>
                        <th class="py-3.5 px-4 font-semibold">Cliente</th>
                        <th class="py-3.5 px-4 font-semibold">Empresa / Razón Social</th>
                        <th class="py-3.5 px-4 font-semibold">Email</th>
                        <th class="py-3.5 px-4 font-semibold">Sitios Contratados</th>
                        <th class="py-3.5 px-4 font-semibold">Estado</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    @forelse($clientes as $cliente)
                        <tr class="hover:bg-slate-900/40 transition">
                            <td class="py-3.5 px-4 font-semibold text-slate-100">
                                {{ $cliente->nombre }}
                            </td>
                            <td class="py-3.5 px-4 text-slate-300">{{ $cliente->empresa ?? 'Particular' }}</td>
                            <td class="py-3.5 px-4 text-xs text-slate-400">{{ $cliente->email }}</td>
                            <td class="py-3.5 px-4 text-indigo-400 font-semibold">{{ $cliente->sitios->count() }} sitios</td>
                            <td class="py-3.5 px-4">
                                @if($cliente->activo)
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">Activo</span>
                                @else
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-800 text-slate-400">Inactivo</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-8 text-center text-slate-500">No hay clientes registrados aún.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>

</x-layouts.panel>
