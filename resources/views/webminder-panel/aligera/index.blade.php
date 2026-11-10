<x-layouts.panel title="Licencias Aligera" headerTitle="Licencias Aligera" headerSubtitle="Licencias anuales de Aligera Pro: las crea el pago en Stripe; el plugin las activa y descarga las actualizaciones de aquí.">
    @php
        $boton = 'inline-flex items-center px-3 py-1.5 rounded-lg text-xs font-semibold transition';
        $campo = 'rounded-lg bg-slate-900 border border-slate-700 text-slate-200 text-sm px-3 py-2';
    @endphp

    <div class="max-w-5xl mx-auto space-y-8">
        @foreach(['success' => 'text-emerald-300', 'error' => 'text-rose-300'] as $tipo => $color)
            @if(session($tipo))<p class="text-sm {{ $color }}">{{ session($tipo) }}</p>@endif
        @endforeach

        <p class="text-xs text-slate-400">
            Versión publicada: {{ $version ?? 'ninguna (php artisan aligera:publicar <zip>)' }} ·
            Webhook de Stripe: <code>{{ route('aligera.stripe') }}</code>
            @unless(config('services.aligera.stripe_webhook')) · <span class="text-rose-300">falta ALIGERA_STRIPE_WEBHOOK_SECRET en el .env</span>@endunless
        </p>

        <flux:card class="bg-slate-950/60 border border-slate-800 p-6 rounded-2xl space-y-4">
            <h2 class="text-sm font-semibold text-slate-200">Crear una licencia a mano</h2>
            <form method="POST" action="{{ route('webminder-panel.aligera.store') }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <input name="email" type="email" required placeholder="email del cliente" class="{{ $campo }} flex-1 min-w-48" aria-label="Email">
                <select name="plan" class="{{ $campo }}" aria-label="Plan">
                    @foreach(\App\Webminder\Panel\Models\AligeraLicencia::PLANES as $id => [$nombre, $webs])
                        <option value="{{ $id }}">{{ $nombre }} ({{ $webs }} {{ $webs === 1 ? 'web' : 'webs' }})</option>
                    @endforeach
                </select>
                <input name="meses" type="number" min="1" max="120" value="12" class="{{ $campo }} w-20" aria-label="Meses">
                <input name="nota" placeholder="nota (opcional)" class="{{ $campo }}" aria-label="Nota">
                <label class="text-xs text-slate-300 flex items-center gap-1"><input type="checkbox" name="enviar" value="1" checked> Enviarle la clave</label>
                <button class="{{ $boton }} bg-indigo-600 hover:bg-indigo-500 text-white">Crear</button>
            </form>
        </flux:card>

        <flux:card class="bg-slate-950/60 border border-slate-800 p-6 rounded-2xl space-y-4">
            <form method="GET" class="flex gap-3">
                <input name="q" value="{{ $q }}" placeholder="Buscar por email, clave o web" class="{{ $campo }} flex-1" aria-label="Buscar">
                <button class="{{ $boton }} bg-slate-800 hover:bg-slate-700 text-slate-300">Buscar</button>
            </form>
            @forelse($licencias as $l)
                <div class="p-4 rounded-xl bg-slate-900/60 border {{ $l->vigente() ? 'border-slate-800' : 'border-rose-500/30' }} space-y-2">
                    <div class="flex flex-wrap items-center gap-3">
                        <code class="text-sm text-slate-200 select-all">{{ $l->clave }}</code>
                        <span class="text-sm text-slate-300 flex-1">{{ $l->email }} · {{ \App\Webminder\Panel\Models\AligeraLicencia::PLANES[$l->plan][0] }}</span>
                        <span class="text-xs {{ $l->vigente() ? 'text-emerald-400' : 'text-rose-400' }}">
                            {{ $l->vigente() ? 'Válida hasta' : 'Caducó el' }} {{ $l->caduca_en->timezone('Europe/Madrid')->format('d/m/Y') }}
                        </span>
                        <form method="POST" action="{{ route('webminder-panel.aligera.reenviar', $l) }}">@csrf<button class="{{ $boton }} bg-slate-800 hover:bg-slate-700 text-slate-300">Reenviar clave</button></form>
                        @if($l->vigente())
                            <form method="POST" action="{{ route('webminder-panel.aligera.anular', $l) }}" onsubmit="return confirm('¿Anular {{ $l->clave }}? Dejará de recibir actualizaciones.')">@csrf<button class="{{ $boton }} bg-rose-600/80 hover:bg-rose-500 text-white">Anular</button></form>
                        @endif
                    </div>
                    <p class="text-xs text-slate-400">
                        {{ $l->stripe_suscripcion ? 'Stripe ' . $l->stripe_suscripcion : 'Creada a mano' }}{{ $l->nota ? ' · ' . $l->nota : '' }} ·
                        {{ $l->activaciones->count() }} de {{ $l->sitios_max }} {{ $l->sitios_max === 1 ? 'web' : 'webs' }}
                    </p>
                    @foreach($l->activaciones as $a)
                        <div class="flex items-center gap-3 text-xs text-slate-300 pl-3">
                            <span class="flex-1">{{ $a->dominio }} · {{ $a->version ? 'v' . $a->version : '' }} · visto {{ $a->visto_en?->timezone('Europe/Madrid')->format('d/m/Y H:i') ?? 'nunca' }}</span>
                            <form method="POST" action="{{ route('webminder-panel.aligera.liberar', $a) }}">@csrf<button class="text-slate-400 hover:text-rose-300">Liberar</button></form>
                        </div>
                    @endforeach
                </div>
            @empty
                <p class="text-sm text-slate-400">{{ $q !== '' ? 'Nada coincide con la búsqueda.' : 'Aún no hay licencias.' }}</p>
            @endforelse
        </flux:card>
    </div>
</x-layouts.panel>
