<x-layouts.panel title="Cerrojo" headerTitle="Cerrojo" headerSubtitle="Versión de Cerrojo en cada web e instalación de plugins: está cerrada siempre y solo se abre desde aquí, por tiempo limitado.">
    @php
        $boton = 'inline-flex items-center px-3 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer';
        $hora = fn ($t) => \Illuminate\Support\Carbon::createFromTimestamp($t, 'Europe/Madrid')->format('H:i');
    @endphp

    <div class="max-w-6xl mx-auto space-y-8">
        @foreach(['success' => 'text-emerald-300', 'error' => 'text-rose-300'] as $tipo => $color)
            @if(session($tipo))<p class="text-sm {{ $color }}">{{ session($tipo) }}</p>@endif
        @endforeach
        @if(session('hallazgos'))
            <ul class="space-y-1 text-xs text-slate-300 list-disc pl-5">
                @foreach(session('hallazgos') as $h)<li>{{ $h }}</li>@endforeach
            </ul>
        @endif

        <flux:card class="bg-slate-950/60 border border-slate-800 p-6 rounded-2xl flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-sm text-slate-200">Versión en el panel: <strong>{{ $version ?? 'ninguna' }}</strong></p>
                <p class="text-xs text-slate-400">Va firmada con la clave del panel: una web solo la acepta si viene de aquí, y si deja de responder vuelve sola a la anterior.</p>
            </div>
            @if($version)
                <form method="POST" action="{{ route('webminder-panel.cerrojo.todos') }}">
                    @csrf
                    <button class="{{ $boton }} bg-indigo-600 hover:bg-indigo-500 text-white">Actualizar en todas las webs</button>
                </form>
            @else
                <p class="text-xs text-amber-300">Sube cerrojo.php a {{ config('services.cerrojo.archivo') }}</p>
            @endif
        </flux:card>

        <flux:card class="bg-slate-950/60 border border-slate-800 p-0 rounded-2xl overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-xs text-slate-400 text-left">
                    <tr class="border-b border-slate-800">
                        <th class="p-4">Web</th><th class="p-4">Cerrojo</th><th class="p-4">Instalar plugins</th><th class="p-4">Última revisión</th><th class="p-4"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    @foreach($sitios as $sitio)
                        @php
                            $e = $sitio->estadoActual();
                            $c = $e?->datos['cerrojo'] ?? null;
                            // Lo de Cerrojo en el panel (instalar, permiso, limpiar) necesita el worker 2.5+; los anteriores no dicen su versión
                            $worker = $e?->datos['worker_version'] ?? null;
                            $worker_ok = $worker && version_compare($worker, '2.5.0', '>=');
                            $instalado = $c['installed'] ?? false;
                            $vieja = $instalado && $version && version_compare($c['version'], $version, '<');
                            $hasta = (int) ($c['permiso_hasta'] ?? 0);
                        @endphp
                        <tr class="align-top">
                            <td class="p-4">
                                <a href="{{ route('webminder-panel.sitios.show', $sitio) }}" class="text-slate-200 hover:text-white">{{ $sitio->nombre }}</a>
                                <p class="text-xs text-slate-500">
                                    {{ $e ? 'comprobada ' . $e->created_at->diffForHumans() : 'nunca comprobada' }} · worker {{ $worker ?? 'anterior a 2.5' }}
                                    · <a href="{{ route('webminder-panel.sitios.verificar', $sitio) }}" class="underline hover:text-slate-300">comprobar ahora</a>
                                </p>
                                @foreach(array_reverse($c['apartados'] ?? []) as $a)
                                    <p class="text-xs text-rose-300">Apartado {{ $a['plugin'] }} · {{ \Illuminate\Support\Carbon::createFromTimestamp($a['fecha'], 'Europe/Madrid')->format('d/m H:i') }}</p>
                                @endforeach
                            </td>
                            <td class="p-4">
                                @if($c === null)<span class="text-slate-500">sin datos</span>
                                @elseif(!$instalado)<span class="text-amber-300">no instalado</span>
                                @else<span class="{{ $vieja ? 'text-amber-300' : 'text-emerald-400' }}">{{ $c['version'] }}{{ $c['mu_plugin'] ? '' : ' (no es mu-plugin)' }}</span>@endif
                                @if(!empty($c['copia_plugins']))
                                    <p class="text-xs text-amber-300">En plugins/cerrojo hay la {{ $c['copia_plugins'] }}, pero carga la de mu-plugins. Desde la 1.5.1 se cambia sola al visitar la web; si no, súbela a mu-plugins.</p>
                                @endif
                            </td>
                            <td class="p-4">
                                @if(!$worker_ok)
                                    <span class="text-amber-300">actualiza el worker a 2.5 (en Actualizaciones de la web)</span>
                                @elseif(!isset($c['permiso_hasta']))
                                    <span class="text-amber-300">necesita Cerrojo 1.5</span>
                                @elseif($hasta === -1)
                                    <span class="text-amber-300">abierta en wp-config</span>
                                @elseif($hasta > 0)
                                    <span class="text-amber-300">abierta hasta las {{ $hora($hasta) }}</span>
                                    <form method="POST" action="{{ route('webminder-panel.cerrojo.cerrar', $sitio) }}" class="mt-2">
                                        @csrf
                                        <button class="{{ $boton }} bg-slate-800 hover:bg-slate-700 text-slate-200">Cerrar ya</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('webminder-panel.cerrojo.permitir', $sitio) }}" class="flex items-center gap-2">
                                        @csrf
                                        <span class="text-emerald-400">cerrada</span>
                                        <select name="minutos" class="bg-slate-900 border border-slate-700 rounded-lg text-xs text-slate-300 px-2 py-1">
                                            <option value="30">30 min</option><option value="60" selected>1 h</option><option value="120">2 h</option>
                                        </select>
                                        <button class="{{ $boton }} bg-amber-600 hover:bg-amber-500 text-white">Abrir</button>
                                    </form>
                                @endif
                            </td>
                            <td class="p-4 {{ ($c['pending'] ?? 0) ? 'text-rose-400' : 'text-slate-400' }}">
                                {{ $instalado ? (($c['pending'] ?? 0) ? $c['pending'] . ' hallazgos' : 'sin hallazgos') : '' }}
                            </td>
                            <td class="p-4">
                                <div class="flex flex-wrap gap-2 justify-end">
                                    @if($worker_ok && $version && (!$instalado || $vieja))
                                        <form method="POST" action="{{ route('webminder-panel.cerrojo.instalar', $sitio) }}">
                                            @csrf
                                            <button class="{{ $boton }} bg-indigo-600 hover:bg-indigo-500 text-white">{{ $instalado ? 'Actualizar' : 'Instalar' }} {{ $version }}</button>
                                        </form>
                                    @endif
                                    @if($worker && version_compare($worker, '2.7.0', '>=') && $instalado)
                                        <a href="{{ route('webminder-panel.cerrojo.usuarios', $sitio) }}" class="{{ $boton }} bg-slate-800 hover:bg-slate-700 text-slate-200">Usuarios</a>
                                    @endif
                                    @if($worker_ok && isset($c['permiso_hasta']))
                                        <form method="POST" action="{{ route('webminder-panel.cerrojo.limpiar', $sitio) }}">
                                            @csrf
                                            <button class="{{ $boton }} bg-slate-800 hover:bg-slate-700 text-slate-200">Limpiar</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </flux:card>
    </div>
</x-layouts.panel>
