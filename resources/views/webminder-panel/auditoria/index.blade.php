<x-layouts.panel title="Auditoría de Eventos - Webminder Panel" headerTitle="Registro de Auditoría de Eventos" headerSubtitle="Historial completo de peticiones, verificaciones y comandos ejecutados remotamente">

    <flux:card class="bg-slate-950/60 border border-slate-800 p-6 rounded-2xl">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-300">
                <thead class="bg-slate-900/80 text-xs uppercase tracking-wider text-slate-400 border-b border-slate-800">
                    <tr>
                        <th class="py-3.5 px-4 font-semibold">Acción</th>
                        <th class="py-3.5 px-4 font-semibold">Cliente</th>
                        <th class="py-3.5 px-4 font-semibold">Sitio Web</th>
                        <th class="py-3.5 px-4 font-semibold">Resultado</th>
                        <th class="py-3.5 px-4 font-semibold">Mensaje</th>
                        <th class="py-3.5 px-4 font-semibold">Fecha</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    @forelse($eventos as $evento)
                        <tr class="hover:bg-slate-900/40 transition">
                            <td class="py-3.5 px-4 font-semibold text-slate-200">
                                {{ \App\Webminder\Panel\Models\EventoAuditoria::getAccionLabel($evento->accion) }}
                            </td>
                            <td class="py-3.5 px-4 text-slate-400">{{ $evento->cliente->nombre ?? '-' }}</td>
                            <td class="py-3.5 px-4 font-medium text-slate-100">{{ $evento->sitio->nombre ?? '-' }}</td>
                            <td class="py-3.5 px-4">
                                @if($evento->resultado == 'exito')
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">ÉXITO</span>
                                @else
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-rose-500/10 text-rose-400 border border-rose-500/20">ERROR</span>
                                @endif
                            </td>
                            <td class="py-3.5 px-4 text-slate-300 text-xs">{{ $evento->mensaje }}</td>
                            <td class="py-3.5 px-4 text-xs text-slate-400">{{ $evento->created_at->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center text-slate-500">No hay eventos registrados aún.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $eventos->links() }}
        </div>
    </flux:card>

</x-layouts.panel>
