<x-layouts.panel title="Dashboard - Webminder Panel" headerTitle="Dashboard General" headerSubtitle="Monitoreo centralizado y estado en tiempo real de todos tus sitios web">

    <!-- Overview Stats Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <!-- Card 1: Total Sitios -->
        <flux:card class="bg-slate-950/60 border border-slate-800 p-6 rounded-2xl relative overflow-hidden">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Total Sitios</p>
                    <h3 class="text-3xl font-bold text-slate-100 mt-2">{{ $estadisticas['total_sitios'] }}</h3>
                </div>
                <div class="w-12 h-12 rounded-xl bg-indigo-500/10 border border-indigo-500/20 text-indigo-400 flex items-center justify-center text-2xl">
                    🌐
                </div>
            </div>
            <div class="mt-4 flex items-center gap-2 text-xs text-slate-400">
                <span class="text-emerald-400 font-semibold">{{ $estadisticas['clientes_activos'] }}</span> clientes activos
            </div>
        </flux:card>

        <!-- Card 2: Sitios Online -->
        <flux:card class="bg-slate-950/60 border border-slate-800 p-6 rounded-2xl relative overflow-hidden">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Sitios Online</p>
                    <h3 class="text-3xl font-bold text-emerald-400 mt-2">{{ $estadisticas['sitios_online'] }}</h3>
                </div>
                <div class="w-12 h-12 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 flex items-center justify-center text-2xl">
                    ✅
                </div>
            </div>
            <div class="mt-4 text-xs text-slate-400">
                Operando sin interrupciones
            </div>
        </flux:card>

        <!-- Card 3: Sitios Offline / Riesgo -->
        <flux:card class="bg-slate-950/60 border border-slate-800 p-6 rounded-2xl relative overflow-hidden">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Sitios Offline</p>
                    <h3 class="text-3xl font-bold text-rose-400 mt-2">{{ $estadisticas['sitios_offline'] }}</h3>
                </div>
                <div class="w-12 h-12 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-400 flex items-center justify-center text-2xl">
                    ⚠️
                </div>
            </div>
            <div class="mt-4 text-xs text-slate-400">
                {{ $estadisticas['sitios_en_mantenimiento'] }} en mantenimiento
            </div>
        </flux:card>

        <!-- Card 4: Salud Promedio -->
        <flux:card class="bg-slate-950/60 border border-slate-800 p-6 rounded-2xl relative overflow-hidden">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Salud Promedio</p>
                    <h3 class="text-3xl font-bold text-indigo-400 mt-2">{{ round($salud_promedio, 1) }}%</h3>
                </div>
                <div class="w-12 h-12 rounded-xl bg-indigo-500/10 border border-indigo-500/20 text-indigo-400 flex items-center justify-center text-2xl">
                    🛡️
                </div>
            </div>
            <div class="mt-4 text-xs text-slate-400">
                Score promedio de seguridad
            </div>
        </flux:card>
    </div>

    <!-- Section 1: Recent Sites Table -->
    <flux:card class="bg-slate-950/60 border border-slate-800 p-6 rounded-2xl mb-8">
        <div class="flex items-center justify-between mb-6 pb-4 border-b border-slate-800/80">
            <div>
                <flux:heading size="lg" class="text-slate-100 font-bold">Sitios Web Monitoreados</flux:heading>
                <flux:subheading class="text-slate-400 text-xs">Estado de verificación reciente y puntuación de seguridad</flux:subheading>
            </div>
            <flux:button href="/webminder-panel/sitios" variant="primary" class="bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold px-4 py-2 rounded-xl">
                Ver Todos los Sitios →
            </flux:button>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-300">
                <thead class="bg-slate-900/80 text-xs uppercase tracking-wider text-slate-400 border-b border-slate-800">
                    <tr>
                        <th class="py-3.5 px-4 font-semibold">Sitio Web</th>
                        <th class="py-3.5 px-4 font-semibold">Cliente</th>
                        <th class="py-3.5 px-4 font-semibold">Estado</th>
                        <th class="py-3.5 px-4 font-semibold">Seguridad</th>
                        <th class="py-3.5 px-4 font-semibold">Actualizaciones</th>
                        <th class="py-3.5 px-4 font-semibold">Última Verificación</th>
                        <th class="py-3.5 px-4 font-semibold text-right">Acción</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    @forelse($sitios_recientes as $sitio)
                        @php
                            $est = $sitio->estadoActual();
                            $secScore = $est?->puntuacionSeguridad() ?? 100;
                            $updates = $est?->datos['updates_pending'] ?? 0;
                        @endphp
                        <tr class="hover:bg-slate-900/40 transition">
                            <td class="py-3.5 px-4 font-medium text-slate-100">
                                <a href="/webminder-panel/sitios/{{ $sitio->id }}" class="hover:text-indigo-400 transition font-semibold">
                                    {{ $sitio->nombre }}
                                </a>
                                <p class="text-xs text-slate-400 font-normal">{{ parse_url($sitio->url, PHP_URL_HOST) }}</p>
                            </td>
                            <td class="py-3.5 px-4 text-slate-300">{{ $sitio->cliente->nombre }}</td>
                            <td class="py-3.5 px-4">
                                @if(strtolower($sitio->estado) == 'online')
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                        ONLINE
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-rose-500/10 text-rose-400 border border-rose-500/20">
                                        OFFLINE
                                    </span>
                                @endif
                            </td>
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
                            <td class="py-3.5 px-4 text-xs text-slate-400">
                                {{ $sitio->ultima_verificacion?->diffForHumans() ?? 'Sin verificar' }}
                            </td>
                            <td class="py-3.5 px-4 text-right">
                                <a href="/webminder-panel/sitios/{{ $sitio->id }}" class="inline-flex items-center px-3 py-1.5 rounded-lg bg-slate-900 hover:bg-slate-800 border border-slate-700 text-xs font-medium text-slate-200 transition">
                                    Detalles →
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
    </flux:card>

    <!-- Section 2: Recent Audit Log Events -->
    <flux:card class="bg-slate-950/60 border border-slate-800 p-6 rounded-2xl">
        <div class="flex items-center justify-between mb-6 pb-4 border-b border-slate-800/80">
            <div>
                <flux:heading size="lg" class="text-slate-100 font-bold">Registro de Auditoría de Eventos</flux:heading>
                <flux:subheading class="text-slate-400 text-xs">Últimas operaciones ejecutadas remotamente en los sitios</flux:subheading>
            </div>
            <flux:button href="/webminder-panel/auditoria" variant="filled" class="bg-slate-900 hover:bg-slate-800 text-slate-300 text-xs font-medium px-4 py-2 rounded-xl border border-slate-800">
                Ver Historial Completo →
            </flux:button>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-300">
                <thead class="bg-slate-900/80 text-xs uppercase tracking-wider text-slate-400 border-b border-slate-800">
                    <tr>
                        <th class="py-3.5 px-4 font-semibold">Acción</th>
                        <th class="py-3.5 px-4 font-semibold">Cliente</th>
                        <th class="py-3.5 px-4 font-semibold">Sitio Web</th>
                        <th class="py-3.5 px-4 font-semibold">Resultado</th>
                        <th class="py-3.5 px-4 font-semibold">Hora</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    @forelse($eventos_recientes as $evento)
                        <tr class="hover:bg-slate-900/40 transition">
                            <td class="py-3.5 px-4 font-semibold text-slate-200">
                                {{ \App\Webminder\Panel\Models\EventoAuditoria::getAccionLabel($evento->accion) }}
                            </td>
                            <td class="py-3.5 px-4 text-slate-400">{{ $evento->cliente->nombre ?? '-' }}</td>
                            <td class="py-3.5 px-4 text-slate-300 font-medium">{{ $evento->sitio->nombre ?? '-' }}</td>
                            <td class="py-3.5 px-4">
                                @if($evento->resultado == 'exito')
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                        ÉXITO
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-rose-500/10 text-rose-400 border border-rose-500/20">
                                        ERROR
                                    </span>
                                @endif
                            </td>
                            <td class="py-3.5 px-4 text-xs text-slate-400">{{ $evento->created_at->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-8 text-center text-slate-500">No hay eventos registrados aún.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>

</x-layouts.panel>
