<x-layouts.panel title="Sitios Web - Webminder Panel" headerTitle="Sitios Web Monitoreados" headerSubtitle="Inventario general de plataformas y sitios gestionados">

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-slate-100">Sitios Registrados</h1>
            <p class="text-xs text-slate-400">Total: {{ $sitios->total() }} plataformas en seguimiento</p>
        </div>
        <flux:button href="/webminder-panel/sitios/create" variant="primary" class="bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold px-4 py-2.5 rounded-xl shadow-lg shadow-indigo-600/30">
            + Nuevo Sitio Web
        </flux:button>
    </div>

    <flux:card class="bg-slate-950/60 border border-slate-800 p-6 rounded-2xl">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-300">
                <thead class="bg-slate-900/80 text-xs uppercase tracking-wider text-slate-400 border-b border-slate-800">
                    <tr>
                        <th class="py-3.5 px-4 font-semibold">Sitio Web</th>
                        <th class="py-3.5 px-4 font-semibold">Cliente</th>
                        <th class="py-3.5 px-4 font-semibold">Estado</th>
                        <th class="py-3.5 px-4 font-semibold">Tecnología</th>
                        <th class="py-3.5 px-4 font-semibold">Seguridad</th>
                        <th class="py-3.5 px-4 font-semibold">Actualizaciones</th>
                        <th class="py-3.5 px-4 font-semibold text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    @forelse($sitios as $sitio)
                        @php
                            $estado = $sitio->estadoActual();
                            $secScore = $estado?->puntuacionSeguridad() ?? 100;
                            $updates = $estado?->datos['updates_pending'] ?? 0;
                        @endphp
                        <tr class="hover:bg-slate-900/40 transition">
                            <td class="py-3.5 px-4 font-medium text-slate-100">
                                <a href="/webminder-panel/sitios/{{ $sitio->id }}" class="text-indigo-400 hover:underline font-semibold text-base">
                                    {{ $sitio->nombre }}
                                </a>
                                <p class="text-xs text-slate-400 font-normal">{{ parse_url($sitio->url, PHP_URL_HOST) }}</p>
                            </td>
                            <td class="py-3.5 px-4 text-slate-300">{{ $sitio->cliente->nombre }}</td>
                            <td class="py-3.5 px-4">
                                @if(strtolower($sitio->estado) == 'online')
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                        ONLINE
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-rose-500/10 text-rose-400 border border-rose-500/20">
                                        OFFLINE
                                    </span>
                                @endif
                            </td>
                            <td class="py-3.5 px-4 text-slate-300 font-medium">{{ $sitio->tecnologia ?? 'WordPress' }}</td>
                            <td class="py-3.5 px-4">
                                @if($secScore >= 80)
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                        {{ $secScore }}% (Seguro)
                                    </span>
                                @elseif($secScore >= 50)
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-400 border border-amber-500/20">
                                        {{ $secScore }}% (Revisar)
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-rose-500/10 text-rose-400 border border-rose-500/20">
                                        {{ $secScore }}% (Riesgo)
                                    </span>
                                @endif
                            </td>
                            <td class="py-3.5 px-4">
                                @if($updates > 0)
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-400 border border-amber-500/20">
                                        ⚠️ {{ $updates }} pendientes
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                        ✓ Al día
                                    </span>
                                @endif
                            </td>
                            <td class="py-3.5 px-4 text-right">
                                <a href="/webminder-panel/sitios/{{ $sitio->id }}" class="inline-flex items-center px-3 py-1.5 rounded-lg bg-slate-900 hover:bg-slate-800 border border-slate-700 text-xs font-medium text-slate-200 transition">
                                    Ver Detalles →
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-8 text-center text-slate-500">No hay sitios registrados aún.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $sitios->links() }}
        </div>
    </flux:card>

</x-layouts.panel>
