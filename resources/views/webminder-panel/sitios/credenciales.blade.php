<x-layouts.panel title="Credenciales API - {{ $sitio->nombre }}" headerTitle="Credenciales de Conexión API" headerSubtitle="Claves de autenticación HMAC para el agente remoto">

    @php
        $tokenData = json_encode([
            'id' => $credencial?->client_id,
            'secret' => $credencial?->client_secret,
            'url' => $sitio->url
        ]);
        $quickToken = base64_encode($tokenData);
    @endphp

    <div class="max-w-2xl mx-auto">
        <flux:card class="bg-slate-950/60 border border-slate-800 p-8 rounded-2xl">
            <div class="mb-6 pb-4 border-b border-slate-800">
                <flux:heading size="lg" class="text-slate-100 font-bold">🔑 Claves de Enlace con WordPress</flux:heading>
                <flux:subheading class="text-slate-400 text-xs">Copia estas credenciales y pégalas en las opciones del plugin Webminder Worker instalado en <strong>{{ $sitio->nombre }}</strong> (Herramientas &rarr; Ongoing Mantenimiento).</flux:subheading>
            </div>

            <div class="space-y-6">
                <!-- Option 1: Quick Token -->
                <div class="p-4 rounded-xl bg-indigo-500/10 border border-indigo-500/20">
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-xs font-bold text-indigo-300 uppercase tracking-wider">⚡ Option A: Token de Enlace Rápido (Recomendado)</label>
                        <button type="button" onclick="navigator.clipboard.writeText('{{ $quickToken }}'); alert('¡Token de enlace rápido copiado!');" class="text-xs font-semibold px-3 py-1 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg transition cursor-pointer">
                            Copiar Token Rápido
                        </button>
                    </div>
                    <input type="text" readonly value="{{ $quickToken }}" class="w-full bg-slate-950 px-3.5 py-2.5 rounded-xl border border-slate-800 font-mono text-xs text-sky-400 select-all focus:outline-none" />
                    <p class="text-[11px] text-slate-400 mt-1.5">Pega este código en el campo "Token de Enlace Rápido" del plugin de WordPress para vincular automáticamente en 1 solo paso.</p>
                </div>

                <!-- Option 2: Individual Client ID & Secret -->
                <div class="space-y-4 pt-2">
                    <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Option B: Claves Individuales</p>

                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="block text-xs font-semibold text-slate-400">CLIENT ID (ID DE CLIENTE)</label>
                            <button type="button" onclick="navigator.clipboard.writeText('{{ $credencial?->client_id }}'); alert('¡Client ID copiado!');" class="text-[11px] text-indigo-400 hover:underline">
                                Copiar ID
                            </button>
                        </div>
                        <input type="text" readonly value="{{ $credencial?->client_id ?? 'Sin credenciales' }}" class="w-full bg-slate-950 px-3.5 py-2.5 rounded-xl border border-slate-800 font-mono text-xs text-slate-200 select-all focus:outline-none" />
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="block text-xs font-semibold text-slate-400">CLIENT SECRET (CLAVE SECRETA HMAC)</label>
                            <button type="button" onclick="navigator.clipboard.writeText('{{ $credencial?->client_secret }}'); alert('¡Client Secret copiado!');" class="text-[11px] text-indigo-400 hover:underline">
                                Copiar Secret
                            </button>
                        </div>
                        <input type="text" readonly value="{{ $credencial?->client_secret ?? 'Sin credenciales' }}" class="w-full bg-slate-950 px-3.5 py-2.5 rounded-xl border border-slate-800 font-mono text-xs text-slate-200 select-all focus:outline-none" />
                    </div>
                </div>
            </div>

            <div class="mt-8 pt-6 border-t border-slate-800 flex items-center justify-between">
                <form action="/webminder-panel/sitios/{{ $sitio->id }}/regenerar-credenciales" method="POST" class="m-0">
                    @csrf
                    <button type="submit" class="px-4 py-2.5 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-400 hover:bg-amber-500/20 text-xs font-semibold transition cursor-pointer" onclick="return confirm('¿Regenerar credenciales? Deberás actualizar las claves en el plugin del sitio cliente.');">
                        🔄 Regenerar Claves API
                    </button>
                </form>
                <flux:button href="/webminder-panel/sitios/{{ $sitio->id }}" variant="primary" class="bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold px-5 py-2.5 rounded-xl">
                    Entendido, Volver al Sitio
                </flux:button>
            </div>
        </flux:card>
    </div>

</x-layouts.panel>
