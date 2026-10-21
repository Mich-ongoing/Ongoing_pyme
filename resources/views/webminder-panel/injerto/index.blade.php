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
                @php
                    $worker = $sitio->estadoActual()?->datos['worker_version'] ?? null;
                    $puede_localizar = $worker && version_compare($worker, '2.6.0', '>=');
                @endphp
                @foreach($sitio->dominios_scripts['avisados'] as $dominio)
                    @php
                        $ev = $sitio->dominios_scripts['evidencias'][$dominio] ?? null;
                        $loc = session('localizado');
                        $loc = $loc && $loc['sitio'] === $sitio->id && $loc['dominio'] === $dominio ? $loc : null;
                    @endphp
                    <div class="p-3 rounded-xl bg-rose-500/5 border border-rose-500/20 space-y-3">
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="text-sm text-slate-200 flex-1">{{ $sitio->nombre }} → <code class="text-rose-300">{{ $dominio }}</code></span>
                            @if($puede_localizar)
                                <form method="POST" action="{{ route('webminder-panel.injerto.localizar', $sitio) }}">
                                    @csrf
                                    <input type="hidden" name="dominio" value="{{ $dominio }}">
                                    <button class="{{ $boton }} bg-indigo-600 hover:bg-indigo-500 text-white">🔎 Localizar</button>
                                </form>
                            @else
                                <span class="text-xs text-slate-500">Localizar necesita el worker 2.6</span>
                            @endif
                            @foreach(['aceptar' => ['Aceptar', 'bg-emerald-600 hover:bg-emerald-500 text-white'], 'olvidar' => ['Olvidar aviso', 'bg-slate-800 hover:bg-slate-700 text-slate-300']] as $accion => [$texto, $clases])
                                <form method="POST" action="{{ route('webminder-panel.injerto.dominio', $sitio) }}">
                                    @csrf
                                    <input type="hidden" name="dominio" value="{{ $dominio }}"><input type="hidden" name="accion" value="{{ $accion }}">
                                    <button class="{{ $boton }} {{ $clases }}">{{ $texto }}</button>
                                </form>
                            @endforeach
                        </div>
                        @if($ev)
                            <div class="text-xs text-slate-400">
                                Visto el {{ \Illuminate\Support\Carbon::parse($ev['fecha'])->timezone('Europe/Madrid')->format('d/m H:i') }} en {{ $ev['url'] }}:
                                <pre class="mt-1 p-2 rounded-lg bg-slate-950 text-slate-300 whitespace-pre-wrap break-all">{{ $ev['fragmento'] }}</pre>
                            </div>
                        @endif
                        @if($loc)
                            <div class="text-xs space-y-2">
                                @foreach($loc['portada'] ?? [] as $quien => $p)
                                    <p class="{{ $p['aparece'] ? 'text-rose-300' : 'text-emerald-400' }}">
                                        Portada ahora ({{ $quien }}): HTTP {{ $p['codigo'] }} · {{ $p['aparece'] ? 'SÍ aparece' : 'no aparece' }}{{ $p['wordpress'] ? '' : ' · no es la página de WordPress' }}
                                    </p>
                                @endforeach
                                @forelse($loc['hallado'] ?? [] as $h)
                                    <div>
                                        <p class="text-rose-300 font-semibold">{{ $h['donde'] }}</p>
                                        <pre class="mt-1 p-2 rounded-lg bg-slate-950 text-slate-300 whitespace-pre-wrap break-all">{{ $h['trozo'] }}</pre>
                                    </div>
                                @empty
                                    <p class="text-slate-400">No está en la base de datos ni en los {{ $loc['archivos_revisados'] ?? 0 }} archivos de la web.
                                        {{ collect($loc['portada'] ?? [])->contains('aparece', true) ? 'Si aun así sale en la portada, lo mete algo de fuera (Cloudflare, el servidor) o un script que lo carga desde otro dominio.' : 'Tampoco sale ahora en la portada: lo más probable es que fuera una página que no era la web (por ejemplo la de Plesk). Puedes olvidar el aviso.' }}</p>
                                @endforelse
                            </div>
                        @endif
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
