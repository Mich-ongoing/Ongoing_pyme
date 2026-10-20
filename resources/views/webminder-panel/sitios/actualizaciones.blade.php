<x-layouts.panel title="Actualizaciones - {{ $sitio->nombre }}" headerTitle="Actualizaciones de {{ $sitio->nombre }}" headerSubtitle="Elige qué actualizar. Se hace en segundo plano, de uno en uno, con vuelta atrás automática si la web falla.">

    <div class="max-w-5xl mx-auto space-y-8">
        <div class="flex flex-wrap gap-2.5">
            <a href="{{ route('webminder-panel.sitios.show', $sitio) }}" class="inline-flex items-center px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-700 text-slate-300 text-xs font-semibold transition">← Volver al sitio</a>
            <a href="{{ route('webminder-panel.sitios.actualizaciones', [$sitio, 'refrescar' => 1]) }}" class="inline-flex items-center px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-700 text-slate-300 text-xs font-semibold transition">🔄 Volver a consultar en wordpress.org</a>
        </div>

        @if($enCurso)
            <flux:card class="bg-amber-500/5 border border-amber-500/20 p-5 rounded-2xl">
                <p class="text-sm text-amber-300">Hay un lote {{ $enCurso->estado === 'pendiente' ? 'en cola' : 'en curso' }} para este sitio.
                    <a class="underline" href="{{ route('webminder-panel.ejecuciones.show', $enCurso) }}">Ver progreso</a></p>
            </flux:card>
        @endif

        <flux:card class="bg-slate-950/60 border border-slate-800 p-6 rounded-2xl">
            @if($error)
                <p class="text-sm text-rose-400">No se pudo consultar el sitio: {{ $error }}</p>
            @elseif(empty($pendientes))
                <p class="text-sm text-emerald-400">✓ Todo al día. No hay plugins, temas ni traducciones pendientes.</p>
            @else
                <form action="{{ route('webminder-panel.sitios.actualizaciones.store', $sitio) }}" method="POST" class="space-y-6">
                    @csrf
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm text-slate-300">
                            <thead class="bg-slate-900/80 text-xs uppercase tracking-wider text-slate-400 border-b border-slate-800">
                                <tr>
                                    <th class="py-3 px-4"><span class="sr-only">Elegir</span></th>
                                    <th class="py-3 px-4 font-semibold">Elemento</th>
                                    <th class="py-3 px-4 font-semibold">Tipo</th>
                                    <th class="py-3 px-4 font-semibold">Versión</th>
                                    <th class="py-3 px-4 font-semibold">Aviso</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800/60">
                                @foreach($pendientes as $u)
                                    @php $clave = $u['type'] . '|' . $u['item']; $id = 'u' . $loop->index; @endphp
                                    <tr class="hover:bg-slate-900/40">
                                        <td class="py-3 px-4">
                                            <input type="checkbox" id="{{ $id }}" name="items[]" value="{{ $clave }}" @checked(empty($u['requires_license'])) class="w-4 h-4 accent-indigo-500">
                                        </td>
                                        <td class="py-3 px-4 font-medium text-slate-100"><label for="{{ $id }}">{{ $u['name'] }}</label>
                                            <p class="text-xs text-slate-500 font-normal">{{ $u['item'] }}</p></td>
                                        <td class="py-3 px-4 text-xs text-slate-400">{{ ['core' => 'WordPress', 'plugin' => 'Plugin', 'theme' => 'Tema', 'translation' => 'Traducciones'][$u['type']] ?? $u['type'] }}</td>
                                        <td class="py-3 px-4 text-xs">
                                            @if($u['type'] === 'translation')
                                                {{ $u['version'] }} paquetes
                                            @else
                                                <code class="text-slate-400">{{ $u['current_version'] ?? '?' }}</code> → <code class="text-emerald-400">{{ $u['version'] }}</code>
                                            @endif
                                        </td>
                                        <td class="py-3 px-4 text-xs text-amber-300">
                                            @if(!empty($u['requires_license'])) Requiere licencia. @endif
                                            {{ $u['last_error'] ?? '' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <label class="flex items-start gap-3 text-sm text-slate-300">
                        <input type="hidden" name="copia_bd" value="0">
                        <input type="checkbox" name="copia_bd" value="1" checked class="w-4 h-4 mt-0.5 accent-indigo-500">
                        <span>Copia de la base de datos antes de empezar
                            <span class="block text-xs text-slate-500">Se guarda en el propio sitio (las 3 últimas). Si la copia falla, no se actualiza nada.</span></span>
                    </label>

                    <button type="submit" class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold shadow-md shadow-indigo-600/20 transition cursor-pointer">
                        Actualizar lo seleccionado
                    </button>
                </form>
            @endif
        </flux:card>

        @if($historial->isNotEmpty())
            <flux:card class="bg-slate-950/60 border border-slate-800 p-6 rounded-2xl">
                <flux:heading size="lg" class="text-slate-100 font-bold mb-4">Lotes anteriores</flux:heading>
                <ul class="divide-y divide-slate-800/60 text-sm">
                    @foreach($historial as $e)
                        <li class="py-2.5 flex justify-between gap-4">
                            <a class="text-indigo-400 hover:underline" href="{{ route('webminder-panel.ejecuciones.show', $e) }}">{{ $e->created_at->format('d/m/Y H:i') }} · {{ count($e->items) }} elementos</a>
                            <span class="text-xs text-slate-400">{{ $e->estado }}</span>
                        </li>
                    @endforeach
                </ul>
            </flux:card>
        @endif
    </div>
</x-layouts.panel>
