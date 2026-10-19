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
        ];
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
            @if($ejecucion->estado === 'en_curso' && $ejecucion->copia_bd && !$ejecucion->archivo_copia_bd)
                <p class="text-sm text-indigo-400 mt-2">Copiando la base de datos… En sitios grandes tarda unos minutos.</p>
            @endif
            @if($ejecucion->error)
                <p class="text-sm text-rose-400 mt-2">{{ $ejecucion->error }}</p>
            @endif
            @if($ejecucion->archivo_copia_bd)
                <p class="text-xs text-slate-400 mt-2">Copia de la BD en el sitio: <code>{{ $ejecucion->archivo_copia_bd }}</code> · restaurar por SSH con <code>wp db import</code></p>
            @endif
        </flux:card>

        <flux:card class="bg-slate-950/60 border border-slate-800 p-6 rounded-2xl">
            <ul class="divide-y divide-slate-800/60">
                @foreach($ejecucion->items as $item)
                    @php [$texto, $color] = $etiquetas[$item['estado']] ?? [$item['estado'], 'text-slate-400']; @endphp
                    <li class="py-3 flex flex-wrap justify-between gap-2">
                        <div>
                            <p class="text-sm font-medium text-slate-100">{{ $item['nombre'] }}</p>
                            <p class="text-xs text-slate-500">
                                @if($item['tipo'] === 'translation') {{ $item['hasta'] }} paquetes de idioma
                                @else {{ $item['desde'] ?? '?' }} → {{ $item['hasta'] ?? '?' }} @endif
                            </p>
                            @if($item['mensaje'])
                                <p class="text-xs text-slate-400 mt-1">{{ $item['mensaje'] }}</p>
                            @endif
                        </div>
                        <span class="text-xs font-semibold {{ $color }}">{{ $texto }}</span>
                    </li>
                @endforeach
            </ul>
        </flux:card>
    </div>

    @unless($ejecucion->terminada())
        <script>setTimeout(() => location.reload(), 4000);</script>
    @endunless
</x-layouts.panel>
