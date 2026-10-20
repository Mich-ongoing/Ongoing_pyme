<x-layouts.panel title="Injerto - {{ $solicitud->sitio->nombre }}" headerTitle="Código de Injerto en {{ $solicitud->sitio->nombre }}" headerSubtitle="{{ $solicitud->ambito_nombre }} · pedido por {{ $solicitud->usuario }} (IP {{ $solicitud->ip }}) el {{ $solicitud->created_at->format('d/m/Y H:i') }}">
    <div class="max-w-5xl mx-auto space-y-6">
        <a href="{{ route('webminder-panel.injerto.index') }}" class="inline-flex items-center px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-700 text-slate-300 text-xs font-semibold transition">← Injerto</a>
        @foreach(['success' => 'text-emerald-300', 'error' => 'text-rose-300'] as $tipo => $color)
            @if(session($tipo))<p class="text-sm {{ $color }}">{{ session($tipo) }}</p>@endif
        @endforeach

        <flux:card class="bg-slate-950/60 border border-slate-800 p-6 rounded-2xl space-y-4">
            <pre class="text-xs text-slate-200 whitespace-pre-wrap break-words bg-slate-900/80 p-4 rounded-xl max-h-[60vh] overflow-auto">{{ $resumen }}</pre>

            @if($solicitud->estado === 'pendiente')
                <p class="text-xs text-slate-400">Al aprobar, el panel firma este código y se lo envía a la web; la web solo publica código con esta firma.</p>
                <div class="flex gap-3">
                    <form method="POST" action="{{ route('webminder-panel.injerto.aprobar', $solicitud) }}">@csrf
                        <button class="inline-flex items-center px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold">Aprobar y publicar</button>
                    </form>
                    <form method="POST" action="{{ route('webminder-panel.injerto.rechazar', $solicitud) }}">@csrf
                        <button class="inline-flex items-center px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-sm font-semibold">Rechazar</button>
                    </form>
                </div>
            @else
                <p class="text-sm text-slate-300">Estado: <strong>{{ $solicitud->estado }}</strong>{{ $solicitud->decisor ? ' por ' . $solicitud->decisor->name : '' }}{{ $solicitud->decidida_en ? ' el ' . $solicitud->decidida_en->format('d/m/Y H:i') : '' }}{{ $solicitud->error ? ' — ' . $solicitud->error : '' }}</p>
            @endif
        </flux:card>
    </div>
</x-layouts.panel>
