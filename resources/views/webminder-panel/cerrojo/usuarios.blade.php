<x-layouts.panel title="Usuarios · {{ $sitio->nombre }}" headerTitle="Usuarios de Cerrojo" headerSubtitle="{{ $sitio->nombre }} · administradores, cuentas bloqueadas por intentos fallidos y administradores que Cerrojo degradó.">
    @php $boton = 'inline-flex items-center px-3 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer'; @endphp

    <div class="max-w-5xl mx-auto space-y-6">
        <a href="{{ route('webminder-panel.cerrojo.index') }}" class="inline-flex items-center px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-700 text-slate-300 text-xs font-semibold transition">← Cerrojo</a>
        @foreach(['success' => 'text-emerald-300', 'error' => 'text-rose-300'] as $tipo => $color)
            @if(session($tipo))<p class="text-sm {{ $color }}">{{ session($tipo) }}</p>@endif
        @endforeach

        <flux:card class="bg-slate-950/60 border border-slate-800 p-0 rounded-2xl overflow-x-auto">
            @if($usuarios === null)
                <p class="p-6 text-sm text-rose-300">No se pudo leer la lista: {{ $fallo }}</p>
            @elseif(!$usuarios)
                <p class="p-6 text-sm text-slate-400">Nada que mostrar.</p>
            @else
                <table class="w-full text-sm">
                    <thead class="text-xs text-slate-400 text-left">
                        <tr class="border-b border-slate-800"><th class="p-4">Usuario</th><th class="p-4">Estado</th><th class="p-4"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60">
                        @foreach($usuarios as $u)
                            <tr class="align-top">
                                <td class="p-4">
                                    <p class="text-slate-200">{{ $u['login'] }}</p>
                                    <p class="text-xs text-slate-500">{{ $u['email'] }} · {{ implode(', ', $u['roles']) ?: 'sin rol' }}</p>
                                </td>
                                <td class="p-4 text-xs space-y-1">
                                    @if($u['bloqueado'])<p class="text-rose-300">Bloqueado: {{ $u['fallos'] }} intentos fallidos en 24 h</p>
                                    @elseif($u['fallos'])<p class="text-amber-300">{{ $u['fallos'] }} intentos fallidos en 24 h</p>@endif
                                    @if($u['degradado'])<p class="text-rose-300">Cerrojo le quitó el rol de administrador el {{ \Illuminate\Support\Carbon::createFromTimestamp($u['degradado'], 'Europe/Madrid')->format('d/m H:i') }}</p>@endif
                                    @if(in_array('administrator', $u['roles'], true))<p class="{{ $u['admin_confiado'] ? 'text-emerald-400' : 'text-amber-300' }}">{{ $u['admin_confiado'] ? 'Administrador de confianza' : 'Administrador fuera de la lista de confianza' }}</p>@endif
                                    @if($u['cambiar_clave'])<p class="text-amber-300">Tiene que crear contraseña nueva (enlace enviado por correo)</p>@endif
                                </td>
                                <td class="p-4">
                                    <div class="flex flex-wrap gap-2 justify-end">
                                        @if($u['fallos'])
                                            <form method="POST" action="{{ route('webminder-panel.cerrojo.desbloquear', $sitio) }}">
                                                @csrf <input type="hidden" name="login" value="{{ $u['login'] }}">
                                                <button class="{{ $boton }} bg-indigo-600 hover:bg-indigo-500 text-white">Desbloquear</button>
                                            </form>
                                        @endif
                                        @if(!$u['admin_confiado'])
                                            <form method="POST" action="{{ route('webminder-panel.cerrojo.admin', $sitio) }}" onsubmit="return confirm(@js('¿Hacer administrador de confianza a ' . $u['login'] . ' (' . $u['email'] . ')? Solo si sabes que es legítimo.'))">
                                                @csrf <input type="hidden" name="login" value="{{ $u['login'] }}">
                                                <button class="{{ $boton }} bg-amber-600 hover:bg-amber-500 text-white">Autorizar como administrador</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </flux:card>
        <p class="text-xs text-slate-500">Desbloquear deja entrar con su contraseña desde cualquier dispositivo (si el ataque sigue, el bloqueo vuelve). Autorizar como administrador va firmado con la clave del panel.</p>
    </div>
</x-layouts.panel>
