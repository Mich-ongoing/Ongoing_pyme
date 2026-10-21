<x-layouts.panel title="Lote de actualizaciones - {{ $ejecucion->sitio->nombre }}" headerTitle="Lote de actualizaciones" headerSubtitle="{{ $ejecucion->sitio->nombre }} · {{ $ejecucion->created_at->format('d/m/Y H:i') }}{{ $ejecucion->usuario ? ' · ' . $ejecucion->usuario->name : '' }}">

    @php
        $etiquetas = [
            'pendiente' => ['En cola', 'text-slate-400'],
            'en_curso' => ['Actualizando…', 'text-indigo-400'],
            'ok' => ['Actualizado', 'text-emerald-400'],
            'ok_sin_verificar' => ['Actualizado (sin poder comprobar la web)', 'text-amber-300'],
            'revertido' => ['Falló y se volvió a la versión anterior', 'text-amber-300'],
            'licencia' => ['Requiere licencia', 'text-amber-300'],
            'error' => ['Error', 'text-rose-400'],
            'omitido' => ['No se intentó', 'text-slate-500'],
            'cambio_visual' => ['Actualizado, pero la web se ve distinta', 'text-amber-300'],
            'revirtiendo' => ['Volviendo a la versión anterior…', 'text-indigo-400'],
            'revertido_manual' => ['Revertido desde el panel', 'text-slate-300'],
            'error_revertir' => ['No se pudo revertir', 'text-rose-400'],
        ];
        $vistas = ['escritorio' => 'Escritorio', 'movil' => 'Móvil'];
        $revirtiendo = collect($ejecucion->items)->contains('estado', 'revirtiendo');
        $lote = [
            'pendiente' => 'En cola: empezará en cuanto el proceso de colas lo recoja.',
            'en_curso' => 'En curso. Esta página se recarga sola.',
            'completada' => 'Terminado sin errores.',
            'con_errores' => 'Terminado con errores en algunos elementos.',
            'interrumpida' => 'Detenido.',
        ];
    @endphp

    <div class="max-w-5xl mx-auto space-y-6">
        <a href="{{ route('webminder-panel.sitios.actualizaciones', $ejecucion->sitio) }}" class="inline-flex items-center px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-700 text-slate-300 text-xs font-semibold transition">← Actualizaciones del sitio</a>

        <flux:card class="bg-slate-950/60 border border-slate-800 p-6 rounded-2xl" role="status" aria-live="polite">
            <p class="text-sm text-slate-200 font-semibold">{{ $lote[$ejecucion->estado] ?? $ejecucion->estado }}</p>
            @if($ejecucion->estado === 'en_curso' && $ejecucion->progreso)
                <p class="text-sm text-indigo-400 mt-2">{{ $ejecucion->progreso }}</p>
                <p class="text-xs text-slate-500 mt-1">Empezó a las {{ $ejecucion->iniciada_en?->format('H:i') }}. Si el sitio deja de avanzar 5 minutos, el lote se detiene solo.</p>
            @endif
            @if($ejecucion->error)
                <p class="text-sm text-rose-400 mt-2">{{ $ejecucion->error }}</p>
            @endif
            @foreach($ejecucion->items as $item)
                @if($item['estado'] === 'cambio_visual')
                    <p class="text-sm text-amber-300 mt-2">La web se ve distinta tras actualizar <a href="#item-{{ $loop->index }}" class="underline font-semibold">{{ $item['nombre'] }}</a>: compara las capturas de ese elemento y revierte si algo se ha roto.</p>
                @endif
            @endforeach
            @if($ejecucion->archivo_copia_bd)
                <p class="text-xs text-slate-400 mt-2">Copia de la BD en el sitio: <code>{{ $ejecucion->archivo_copia_bd }}</code> · restaurar por SSH con <code>wp db import</code></p>
            @endif
        </flux:card>

        <flux:card class="bg-slate-950/60 border border-slate-800 p-6 rounded-2xl">
            <ul class="divide-y divide-slate-800/60">
                @foreach($ejecucion->items as $item)
                    @php [$texto, $color] = $etiquetas[$item['estado']] ?? [$item['estado'], 'text-slate-400']; @endphp
                    <li id="item-{{ $loop->index }}" class="py-3 flex flex-wrap justify-between gap-2 scroll-mt-6">
                        <div>
                            <p class="text-sm font-medium text-slate-100">{{ $item['nombre'] }}</p>
                            <p class="text-xs text-slate-500">
                                @if($item['tipo'] === 'translation') {{ $item['hasta'] }} paquetes de idioma
                                @else {{ $item['desde'] ?? '?' }} → {{ $item['hasta'] ?? '?' }} @endif
                            </p>
                            @if($item['mensaje'])
                                <p class="text-xs text-slate-400 mt-1">{{ $item['mensaje'] }}</p>
                            @endif
                            @foreach($item['visual'] ?? [] as $vista => $porcentaje)
                                <div class="mt-2 text-xs text-slate-400">
                                    <span class="font-semibold text-slate-300">{{ $vistas[$vista] ?? $vista }}:</span>
                                    @if($porcentaje === null)
                                        sin capturas
                                    @else
                                        <span class="{{ $porcentaje > \App\Webminder\Panel\Services\CapturasService::UMBRAL ? 'text-amber-300' : 'text-emerald-400' }}">{{ number_format($porcentaje, 1, ',', '.') }}% distinto</span>
                                        {{-- Abiertas si este elemento cambió la web; si no, a un clic --}}
                                        <details class="mt-2" @if($item['estado'] === 'cambio_visual') open @endif>
                                            <summary class="cursor-pointer text-slate-400 hover:text-slate-200">Ver capturas</summary>
                                            <div class="grid grid-cols-3 gap-3 mt-2">
                                                @foreach(['antes' => 'Antes', 'despues' => 'Después', 'diff' => 'Cambios en rojo'] as $tipo => $etiqueta)
                                                    @php $src = route('webminder-panel.ejecuciones.captura', [$ejecucion, "{$loop->parent->parent->index}-{$vista}-{$tipo}.jpg"]); @endphp
                                                    <figure>
                                                        <figcaption class="mb-1 text-slate-400">{{ $etiqueta }}</figcaption>
                                                        <a href="{{ $src }}" target="_blank" title="Abrir a tamaño completo" class="block h-96 overflow-y-auto rounded-lg border border-slate-700 bg-white">
                                                            <img src="{{ $src }}" alt="{{ $etiqueta }} · {{ $vistas[$vista] ?? $vista }} · {{ $item['nombre'] }}" loading="lazy" class="w-full">
                                                        </a>
                                                    </figure>
                                                @endforeach
                                            </div>
                                        </details>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        <div class="flex flex-col items-end gap-2">
                            <span class="text-xs font-semibold {{ $color }}">{{ $texto }}</span>
                            @if($ejecucion->terminada() && !empty($item['backup']) && in_array($item['estado'], ['ok', 'ok_sin_verificar', 'cambio_visual'], true))
                                <form method="POST" action="{{ route('webminder-panel.ejecuciones.revertir', [$ejecucion, $loop->index]) }}" onsubmit="return confirm(@js('¿Volver ' . $item['nombre'] . ' a la versión ' . $item['desde'] . '?'))">
                                    @csrf
                                    <button class="px-3 py-1.5 rounded-lg bg-amber-500/10 hover:bg-amber-500/20 border border-amber-500/40 text-amber-300 text-xs font-semibold transition">Revertir a {{ $item['desde'] }}</button>
                                </form>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </flux:card>
    </div>

    @if(!$ejecucion->terminada() || $revirtiendo)
        <script>setTimeout(() => location.reload(), 4000);</script>
    @endif
</x-layouts.panel>
