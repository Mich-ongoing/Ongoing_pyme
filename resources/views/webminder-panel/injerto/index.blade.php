<x-layouts.panel title="Injerto" headerTitle="Injerto" headerSubtitle="Código que las webs piden publicar (se firma aquí al aprobarlo) y scripts externos vigilados en la portada.">
    @php
        $estados = ['aprobada' => ['Aprobada', 'text-emerald-400'], 'rechazada' => ['Rechazada', 'text-slate-400'], 'fallida' => ['La web no la aceptó', 'text-rose-400']];
        $boton = 'inline-flex items-center px-3 py-1.5 rounded-lg text-xs font-semibold transition';
    @endphp

    <div class="max-w-5xl mx-auto space-y-8">
        @foreach(['success' => 'text-emerald-300', 'error' => 'text-rose-300'] as $tipo => $color)
            @if(session($tipo))<p class="text-sm {{ $color }}">{{ session($tipo) }}</p>@endif
        @endforeach

        <flux:card class="bg-slate-950/60 border border-slate-800 p-6 rounded-2xl space-y-4">
            <h2 class="text-sm font-semibold text-slate-200">Pendientes de aprobar</h2>
            @forelse($pendientes as $s)
                <a href="{{ route('webminder-panel.injerto.show', $s) }}" class="block p-4 rounded-xl bg-slate-900/60 hover:bg-slate-900 border border-amber-500/30">
                    <p class="text-sm text-slate-200">{{ $s->sitio->nombre }} · {{ $s->ambito_nombre }}</p>
                    <p class="text-xs text-slate-400">{{ $s->usuario }} · {{ $s->created_at->format('d/m/Y H:i') }}</p>
                </a>
            @empty
                <p class="text-sm text-slate-400">Nada pendiente.</p>
            @endforelse
        </flux:card>

        <flux:card class="bg-slate-950/60 border border-slate-800 p-6 rounded-2xl space-y-4">
            <h2 class="text-sm font-semibold text-slate-200">Scripts externos nuevos sin aceptar</h2>
            <p class="text-xs text-slate-400">Dominios que han aparecido en la portada sin venir de un código aprobado. Acéptalo si es de algo instalado a propósito; si no, revisa la web.</p>
            @forelse($avisados as $sitio)
                @foreach($sitio->dominios_scripts['avisados'] as $dominio)
                    <div class="flex flex-wrap items-center gap-3 p-3 rounded-xl bg-rose-500/5 border border-rose-500/20">
                        <span class="text-sm text-slate-200 flex-1">{{ $sitio->nombre }} → <code class="text-rose-300">{{ $dominio }}</code></span>
                        @foreach(['aceptar' => ['Aceptar', 'bg-emerald-600 hover:bg-emerald-500 text-white'], 'olvidar' => ['Olvidar aviso', 'bg-slate-800 hover:bg-slate-700 text-slate-300']] as $accion => [$texto, $clases])
                            <form method="POST" action="{{ route('webminder-panel.injerto.dominio', $sitio) }}">
                                @csrf
                                <input type="hidden" name="dominio" value="{{ $dominio }}"><input type="hidden" name="accion" value="{{ $accion }}">
                                <button class="{{ $boton }} {{ $clases }}">{{ $texto }}</button>
                            </form>
                        @endforeach
                    </div>
                @endforeach
            @empty
                <p class="text-sm text-emerald-400">✓ Ningún script externo desconocido.</p>
            @endforelse
            <p class="text-xs text-slate-500">Webs vigiladas (con Injerto y el worker): {{ $vigilados->pluck('nombre')->join(', ') ?: 'ninguna todavía' }}</p>
        </flux:card>

        <flux:card class="bg-slate-950/60 border border-slate-800 p-6 rounded-2xl space-y-3">
            <h2 class="text-sm font-semibold text-slate-200">Últimas decisiones</h2>
            @forelse($recientes as $s)
                <a href="{{ route('webminder-panel.injerto.show', $s) }}" class="flex justify-between text-sm py-1 hover:text-white text-slate-300">
                    <span>{{ $s->sitio->nombre }} · {{ $s->ambito_nombre }}</span>
                    <span class="{{ $estados[$s->estado][1] ?? '' }}">{{ $estados[$s->estado][0] ?? $s->estado }}{{ $s->decisor ? ' por ' . $s->decisor->name : '' }}</span>
                </a>
            @empty
                <p class="text-sm text-slate-400">Todavía ninguna.</p>
            @endforelse
        </flux:card>
    </div>
</x-layouts.panel>
