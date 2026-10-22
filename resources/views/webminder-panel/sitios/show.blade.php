<x-layouts.panel title="Sitio: {{ $sitio->nombre }} - Webminder Panel" headerTitle="{{ $sitio->nombre }}" headerSubtitle="Detalles técnicos, auditoría de seguridad WordPress e inventario">

    @php
        $seguridad = $estado_actual?->seguridad() ?? [];
        $score = $estado_actual?->puntuacionSeguridad() ?? 100;
        $admins = $estado_actual?->usuariosAdmin() ?? [];
        $plugins = $estado_actual?->plugins() ?? [];
        $temas = $estado_actual?->temas() ?? [];
        $entorno = $estado_actual?->entorno() ?? [];
        $cerrojo = $estado_actual?->datos['cerrojo'] ?? null;
    @endphp

    <!-- Header Action Banner -->
    <flux:card class="bg-slate-950/60 border border-slate-800 p-6 rounded-2xl mb-8">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-3 mb-2">
                    <h1 class="text-2xl font-bold text-slate-100">{{ $sitio->nombre }}</h1>
                    @if(strtolower($sitio->estado) == 'online')
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                            <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span> ONLINE
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-rose-500/10 text-rose-400 border border-rose-500/20">
                            OFFLINE
                        </span>
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-4 text-xs text-slate-400">
                    <span class="flex items-center gap-1">🌐 <a href="{{ $sitio->url }}" target="_blank" class="text-indigo-400 hover:underline">{{ $sitio->url }}</a></span>
                    <span>🏢 Cliente: <strong class="text-slate-200">{{ $sitio->cliente->nombre }}</strong></span>
                    <span>⚙️ Tecnología: <strong class="text-slate-200">{{ $sitio->tecnologia ?? 'WordPress' }} {{ $estado_actual?->datos['wp_version'] ? 'v'.$estado_actual->datos['wp_version'] : '' }}</strong></span>
                    <span>🕒 Verificación: {{ $sitio->ultima_verificacion?->diffForHumans() ?? 'Nunca' }}</span>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex flex-wrap gap-2.5">
                <a href="/webminder-panel/sitios/{{ $sitio->id }}/verificar" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold shadow-md shadow-indigo-600/20 transition">
                    🔄 Verificar Estado
                </a>
                @if($sitio->enMantenimiento())
                    <form action="/webminder-panel/operaciones/{{ $sitio->id }}/mantenimiento-off" method="POST" class="m-0">
                        @csrf
                        <button type="submit" class="px-4 py-2 rounded-xl bg-amber-500/20 border border-amber-500/30 text-amber-300 hover:bg-amber-500/30 text-xs font-semibold transition cursor-pointer">
                            🔓 Desactivar Mantenimiento
                        </button>
                    </form>
                @else
                    <form action="/webminder-panel/operaciones/{{ $sitio->id }}/mantenimiento-on" method="POST" class="m-0">
                        @csrf
                        <button type="submit" class="px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-700 text-slate-300 text-xs font-semibold transition cursor-pointer">
                            🔒 Modo Mantenimiento
                        </button>
                    </form>
                @endif
                <a href="{{ route('webminder-panel.sitios.actualizaciones', $sitio) }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-700 text-slate-300 text-xs font-semibold transition">
                    ⬆️ Actualizaciones
                </a>
                <form action="{{ route('webminder-panel.operaciones.sso', $sitio) }}" method="POST" target="_blank" class="m-0">
                    @csrf
                    <button type="submit" class="px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-700 text-slate-300 text-xs font-semibold transition cursor-pointer">
                        🚪 Entrar al wp-admin
                    </button>
                </form>
                <a href="/webminder-panel/sitios/{{ $sitio->id }}/credenciales" class="inline-flex items-center px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-700 text-slate-300 text-xs font-semibold transition">
                    🔑 Credenciales API
                </a>
            </div>
        </div>
    </flux:card>

    <!-- Overview Stat Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-4 mb-8">
        <!-- Card 1: Score de Seguridad -->
        <flux:card class="bg-slate-950/60 border border-slate-800 p-5 rounded-2xl">
            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Seguridad</p>
            <h3 class="text-3xl font-bold mt-1 {{ $score >= 80 ? 'text-emerald-400' : ($score >= 50 ? 'text-amber-400' : 'text-rose-400') }}">
                {{ $score }}%
            </h3>
            <p class="text-xs text-slate-400 mt-2">
                @if($score >= 80) ✅ Nivel Seguro @elseif($score >= 50) ⚠️ Requiere atención @else ❌ Riesgo Alto @endif
            </p>
        </flux:card>

        <!-- Card 2: Actualizaciones -->
        <flux:card class="bg-slate-950/60 border border-slate-800 p-5 rounded-2xl">
            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Actualizaciones</p>
            <h3 class="text-3xl font-bold mt-1 {{ ($estado_actual?->datos['updates_pending'] ?? 0) > 0 ? 'text-amber-400' : 'text-emerald-400' }}">
                {{ $estado_actual?->datos['updates_pending'] ?? 0 }}
            </h3>
            <p class="text-xs text-slate-400 mt-2">Plugins y temas pendientes</p>
        </flux:card>

        <!-- Card 3: Administradores -->
        <flux:card class="bg-slate-950/60 border border-slate-800 p-5 rounded-2xl">
            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Administradores</p>
            <h3 class="text-3xl font-bold text-slate-100 mt-1">
                {{ count($admins) ?: ($estado_actual?->datos['admin_count'] ?? 0) }}
            </h3>
            <p class="text-xs mt-2 {{ ($seguridad['insecure_admin_exists'] ?? false) ? 'text-rose-400 font-semibold' : 'text-slate-400' }}">
                @if($seguridad['insecure_admin_exists'] ?? false) ⚠️ Usuario 'admin' activo @else ✓ Cuentas administrativas @endif
            </p>
        </flux:card>

        <!-- Card 4: Total Plugins -->
        <flux:card class="bg-slate-950/60 border border-slate-800 p-5 rounded-2xl">
            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Plugins</p>
            <h3 class="text-3xl font-bold text-indigo-400 mt-1">
                {{ count($plugins) ?: ($estado_actual?->datos['total_plugins'] ?? 0) }}
            </h3>
            <p class="text-xs text-slate-400 mt-2">{{ $estado_actual?->datos['active_plugins'] ?? 0 }} activos</p>
        </flux:card>

        <!-- Card 5: Base de Datos -->
        <flux:card class="bg-slate-950/60 border border-slate-800 p-5 rounded-2xl">
            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Base de Datos</p>
            <h3 class="text-3xl font-bold text-slate-100 mt-1">
                {{ number_format($estado_actual?->tamañoBD() ?? 0, 1) }} MB
            </h3>
            <p class="text-xs text-slate-400 mt-2">Prefijo: <code class="text-indigo-400">{{ $entorno['db_prefix'] ?? 'wp_' }}</code></p>
        </flux:card>
    </div>

    <!-- Section 1: Security Audit Checklist -->
    <flux:card class="bg-slate-950/60 border border-slate-800 p-6 rounded-2xl mb-8">
        <div class="flex items-center justify-between mb-6 pb-4 border-b border-slate-800">
            <div>
                <flux:heading size="lg" class="text-slate-100 font-bold">🛡️ Auditoría de Seguridad WordPress</flux:heading>
                <flux:subheading class="text-slate-400 text-xs">Evaluación de configuraciones críticas y vulnerabilidades conocidas</flux:subheading>
            </div>
            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold {{ $score >= 80 ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-rose-500/10 text-rose-400 border border-rose-500/20' }}">
                Puntuación: {{ $score }}/100
            </span>
        </div>

        @if(!empty($seguridad['checks']))
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @foreach($seguridad['checks'] as $check)
                    <div class="p-4 rounded-xl border {{ $check['status'] == 'pass' ? 'bg-emerald-500/5 border-emerald-500/20' : ($check['status'] == 'warning' ? 'bg-amber-500/5 border-amber-500/20' : 'bg-rose-500/5 border-rose-500/20') }} flex gap-3.5 items-start">
                        <div class="text-xl shrink-0 mt-0.5">
                            @if($check['status'] == 'pass') ✅ @elseif($check['status'] == 'warning') ⚠️ @else ❌ @endif
                        </div>
                        <div>
                            <h4 class="text-sm font-semibold text-slate-200">{{ $check['label'] }}</h4>
                            <p class="text-xs text-slate-400 mt-1">{{ $check['message'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-sm text-slate-500">Haz clic en "Verificar Estado" para ejecutar la auditoría de seguridad completa de este sitio.</p>
        @endif
    </flux:card>

    <!-- Correo: ¿llegan los formularios? -->
    @php
        $correo = $estado_actual?->correo();
        $puede_probar_correo = version_compare($estado_actual?->datos['worker_version'] ?? '0', '2.8.0', '>=');
        $correo_falla = $correo && ($correo['ultimo_error'] ?? 0) > ($correo['ultimo_ok'] ?? 0);
    @endphp
    <flux:card class="bg-slate-950/60 border border-slate-800 p-6 rounded-2xl mb-8">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <flux:heading size="lg" class="text-slate-100 font-bold">📬 Correo de la web (formularios)</flux:heading>
                @if(!$puede_probar_correo)
                    <p class="text-sm text-slate-500 mt-2">Necesita el worker 2.8 o superior.</p>
                @elseif(!$correo)
                    <p class="text-sm text-slate-500 mt-2">La web no ha enviado ningún correo desde que tiene el worker 2.8. Manda una prueba.</p>
                @else
                    <p class="text-sm mt-2 {{ $correo_falla ? 'text-rose-400' : 'text-emerald-400' }}">
                        {{ $correo_falla ? '❌ El último envío falló' : '✅ El último envío salió bien' }}
                    </p>
                    <p class="text-xs text-slate-400 mt-1">
                        Último correo enviado: {{ isset($correo['ultimo_ok']) ? \Illuminate\Support\Carbon::createFromTimestamp($correo['ultimo_ok'])->diffForHumans() : 'nunca' }}
                        @isset($correo['ultimo_error'])
                            · Último fallo: {{ \Illuminate\Support\Carbon::createFromTimestamp($correo['ultimo_error'])->diffForHumans() }}
                        @endisset
                    </p>
                    @if($correo_falla && !empty($correo['error']))
                        <p class="text-xs text-rose-300 mt-1"><code>{{ $correo['error'] }}</code></p>
                    @endif
                    @if(($correo['via'] ?? null) === 'mail')
                        <p class="text-xs text-amber-400 mt-1">⚠️ Envía con PHP mail(), sin SMTP: suele acabar en spam o no llegar. Configura un SMTP.</p>
                    @elseif(isset($correo['via']))
                        <p class="text-xs text-slate-400 mt-1">Envía por: <strong class="text-slate-200">{{ $correo['via'] }}</strong></p>
                    @endif
                @endif
            </div>
            @if($puede_probar_correo)
                <form action="{{ route('webminder-panel.sitios.probar-correo', $sitio) }}" method="POST" class="m-0">
                    @csrf
                    <button type="submit" class="px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-700 text-slate-300 text-xs font-semibold transition cursor-pointer">
                        ✉️ Enviarme un correo de prueba
                    </button>
                </form>
            @endif
        </div>
    </flux:card>

    <!-- Acciones de seguridad (tras un hackeo o una sospecha) -->
    @php
        $puede_acciones = version_compare($estado_actual?->datos['worker_version'] ?? '0', '2.9.0', '>=');
        $gratuitos = collect($plugins)->where('wporg', true)->count();
    @endphp
    <flux:card class="bg-slate-950/60 border border-slate-800 p-6 rounded-2xl mb-8">
        <flux:heading size="lg" class="text-slate-100 font-bold mb-4">🚨 Acciones de seguridad</flux:heading>
        @if(!$puede_acciones)
            <p class="text-sm text-slate-500">Necesita el worker 2.9 o superior.</p>
        @else
            <div class="divide-y divide-slate-800">
                <div class="flex flex-wrap items-center justify-between gap-4 py-4">
                    <div class="max-w-2xl">
                        <h4 class="text-sm font-semibold text-slate-200">Reinstalar todos los plugins gratuitos ({{ $gratuitos }})</h4>
                        <p class="text-xs text-slate-400 mt-1">Vuelve a instalar la misma versión de cada plugin de wordpress.org con su código original, por si un atacante lo ha modificado. Va como una actualización: copia antes, comprueba la web y vuelve atrás si algo falla. Los plugins de pago no se tocan.</p>
                    </div>
                    <form action="{{ route('webminder-panel.sitios.reinstalar-plugins', $sitio) }}" method="POST" class="m-0" onsubmit="return confirm(@js('¿Reinstalar los ' . $gratuitos . ' plugins gratuitos de ' . $sitio->nombre . '?'))">
                        @csrf
                        <button type="submit" @disabled(!$gratuitos) class="px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-700 text-slate-300 text-xs font-semibold transition cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed">♻️ Reinstalar todo</button>
                    </form>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-4 py-4">
                    <div class="max-w-2xl">
                        <h4 class="text-sm font-semibold text-slate-200">Forzar restablecimiento de contraseña</h4>
                        <p class="text-xs text-slate-400 mt-1">Todos los usuarios tendrán que crear una contraseña nueva al volver a entrar (les llega un enlace por correo). <strong class="text-slate-200">Se desconecta al momento a todos los usuarios conectados.</strong></p>
                    </div>
                    <form action="{{ route('webminder-panel.sitios.sesiones', $sitio) }}" method="POST" class="m-0" onsubmit="return confirm(@js('¿Obligar a todos los usuarios de ' . $sitio->nombre . ' a cambiar la contraseña? Se cierran todas las sesiones ahora.'))">
                        @csrf
                        <input type="hidden" name="accion" value="forzar_clave">
                        <button type="submit" class="px-4 py-2 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-400 hover:bg-rose-500/20 text-xs font-semibold transition cursor-pointer">🔑 Forzar restablecimiento</button>
                    </form>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-4 py-4">
                    <div class="max-w-2xl">
                        <h4 class="text-sm font-semibold text-slate-200">Desconectar a todos los usuarios</h4>
                        <p class="text-xs text-slate-400 mt-1">Cierra la sesión de todos los usuarios conectados sin pedirles que cambien la contraseña.</p>
                    </div>
                    <form action="{{ route('webminder-panel.sitios.sesiones', $sitio) }}" method="POST" class="m-0" onsubmit="return confirm(@js('¿Desconectar a todos los usuarios de ' . $sitio->nombre . '?'))">
                        @csrf
                        <input type="hidden" name="accion" value="cerrar">
                        <button type="submit" class="px-4 py-2 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-400 hover:bg-amber-500/20 text-xs font-semibold transition cursor-pointer">🚪 Desconectar a todos</button>
                    </form>
                </div>
            </div>
        @endif
    </flux:card>

    <!-- Grid: Admin Users & Environment -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
        <!-- Section 2: Admin Users -->
        <flux:card class="bg-slate-950/60 border border-slate-800 p-6 rounded-2xl">
            <div class="flex items-center justify-between mb-6 pb-4 border-b border-slate-800">
                <flux:heading size="lg" class="text-slate-100 font-bold">👥 Cuentas de Administrador ({{ count($admins) }})</flux:heading>
            </div>
            @if(!empty($admins))
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-300">
                        <thead class="bg-slate-900/80 text-xs uppercase tracking-wider text-slate-400 border-b border-slate-800">
                            <tr>
                                <th class="py-3 px-4 font-semibold">Usuario</th>
                                <th class="py-3 px-4 font-semibold">Email</th>
                                <th class="py-3 px-4 font-semibold">Seguridad</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60">
                            @foreach($admins as $admin)
                                <tr class="hover:bg-slate-900/40">
                                    <td class="py-3 px-4 font-medium text-slate-100">
                                        {{ $admin['display_name'] }}
                                        <p class="text-xs text-slate-500 font-normal">@ {{ $admin['username'] }}</p>
                                    </td>
                                    <td class="py-3 px-4 text-xs text-slate-400">{{ $admin['email'] }}</td>
                                    <td class="py-3 px-4">
                                        @if($admin['is_insecure'])
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-rose-500/10 text-rose-400 border border-rose-500/20">
                                                ⚠️ Nombre Inseguro
                                            </span>
                                        @else
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                                ✓ Seguro
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-sm text-slate-500">No hay lista de administradores cargada aún.</p>
            @endif
        </flux:card>

        <!-- Section 3: Environment Info -->
        <flux:card class="bg-slate-950/60 border border-slate-800 p-6 rounded-2xl">
            <div class="flex items-center justify-between mb-6 pb-4 border-b border-slate-800">
                <flux:heading size="lg" class="text-slate-100 font-bold">🖥️ Servidor y Entorno PHP/MySQL</flux:heading>
            </div>
            <ul class="divide-y divide-slate-800/60 text-sm text-slate-300">
                <li class="py-2.5 flex justify-between">
                    <span class="text-slate-400">Versión de PHP</span>
                    <span class="font-medium text-slate-100">{{ $entorno['php_version'] ?? ($estado_actual?->datos['php_version'] ?? 'N/A') }}</span>
                </li>
                <li class="py-2.5 flex justify-between">
                    <span class="text-slate-400">Versión de Base de Datos</span>
                    <span class="font-medium text-slate-100">{{ $entorno['db_version'] ?? 'N/A' }}</span>
                </li>
                <li class="py-2.5 flex justify-between">
                    <span class="text-slate-400">Límite de Memoria PHP</span>
                    <span class="font-medium text-indigo-400">{{ $entorno['memory_limit'] ?? ($estado_actual?->datos['memory_limit'] ?? 'N/A') }}</span>
                </li>
                <li class="py-2.5 flex justify-between">
                    <span class="text-slate-400">Tamaño Máximo de Subida</span>
                    <span class="font-medium text-slate-100">{{ $entorno['upload_max_filesize'] ?? 'N/A' }}</span>
                </li>
                <li class="py-2.5 flex justify-between">
                    <span class="text-slate-400">Tiempo Máximo de Ejecución</span>
                    <span class="font-medium text-slate-100">{{ $entorno['max_execution_time'] ?? 'N/A' }}s</span>
                </li>
                <li class="py-2.5 flex justify-between">
                    <span class="text-slate-400">Prefijo de Tablas DB</span>
                    <code class="text-indigo-400 bg-slate-900 px-2 py-0.5 rounded border border-slate-800">{{ $entorno['db_prefix'] ?? 'wp_' }}</code>
                </li>
            </ul>
        </flux:card>
    </div>

    <!-- Section 4: Plugins Table -->
    <flux:card class="bg-slate-950/60 border border-slate-800 p-6 rounded-2xl mb-8">
        <div class="flex items-center justify-between mb-6 pb-4 border-b border-slate-800">
            <div>
                <flux:heading size="lg" class="text-slate-100 font-bold">🔄 Inventario de Plugins ({{ count($plugins) }})</flux:heading>
                <flux:subheading class="text-slate-400 text-xs">Plugins instalados y estado de actualizaciones pendientes</flux:subheading>
            </div>
        </div>

        @if(!empty($plugins))
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-300">
                    <thead class="bg-slate-900/80 text-xs uppercase tracking-wider text-slate-400 border-b border-slate-800">
                        <tr>
                            <th class="py-3.5 px-4 font-semibold">Plugin</th>
                            <th class="py-3.5 px-4 font-semibold">Versión Instalada</th>
                            <th class="py-3.5 px-4 font-semibold">Estado</th>
                            <th class="py-3.5 px-4 font-semibold">Actualización</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60">
                        @foreach($plugins as $plugin)
                            <tr class="hover:bg-slate-900/40">
                                <td class="py-3.5 px-4 font-medium text-slate-100">
                                    {{ $plugin['name'] }}
                                    <p class="text-xs text-slate-500 font-normal">{{ $plugin['author'] }}</p>
                                </td>
                                <td class="py-3.5 px-4"><code class="bg-slate-900 text-slate-300 px-2 py-1 rounded text-xs border border-slate-800">{{ $plugin['version'] }}</code></td>
                                <td class="py-3.5 px-4">
                                    @if($plugin['active'])
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">Activo</span>
                                    @else
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-800 text-slate-400">Inactivo</span>
                                    @endif
                                </td>
                                <td class="py-3.5 px-4">
                                    @if($plugin['update_available'])
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-rose-500/10 text-rose-400 border border-rose-500/20">
                                            ⚠️ Nueva v{{ $plugin['new_version'] }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                            ✓ Al día
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-sm text-slate-500">No hay inventario de plugins cargado aún.</p>
        @endif
    </flux:card>

    <!-- Cerrojo -->
    <flux:card class="bg-slate-950/60 border border-slate-800 p-6 rounded-2xl mb-8">
        <div class="flex flex-wrap items-center justify-between gap-4 mb-4 pb-4 border-b border-slate-800">
            <div>
                <flux:heading size="lg" class="text-slate-100 font-bold">🔒 Cerrojo</flux:heading>
                <flux:subheading class="text-slate-400 text-xs">Escáner de backdoors y endurecimiento propio. Actualizarlo, limpiar y abrir la instalación de plugins: en <a href="{{ route('webminder-panel.cerrojo.index') }}" class="underline hover:text-slate-200">Cerrojo</a>.</flux:subheading>
            </div>
            @if($cerrojo['installed'] ?? false)
                <form action="{{ route('webminder-panel.operaciones.revision-seguridad', $sitio) }}" method="POST" class="m-0">
                    @csrf
                    <button type="submit" class="px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-700 text-slate-300 text-xs font-semibold transition cursor-pointer">🔍 Revisión de seguridad ahora</button>
                </form>
            @endif
        </div>
        @if(!$cerrojo)
            <p class="text-sm text-slate-500">Sin datos: verifica el estado del sitio (requiere worker 2.0).</p>
        @elseif(!$cerrojo['installed'])
            <p class="text-sm text-amber-300">⚠️ Cerrojo no está instalado en este sitio.</p>
        @else
            <p class="text-sm {{ $cerrojo['pending'] ? 'text-rose-400 font-semibold' : 'text-emerald-400' }}">
                v{{ $cerrojo['version'] }}{{ $cerrojo['mu_plugin'] ? ' (mu-plugin)' : ' (plugin normal: se puede desactivar)' }} ·
                {{ $cerrojo['pending'] ? $cerrojo['pending'] . ' hallazgos en la última revisión' : 'sin hallazgos en la última revisión' }}
                @if(isset($cerrojo['permiso_hasta'])) · instalar plugins: {{ $cerrojo['permiso_hasta'] ? 'ABIERTO' : 'cerrado' }}@endif
            </p>
        @endif
        @if(session('hallazgos'))
            <ul class="mt-4 space-y-1 text-xs text-rose-300 list-disc pl-5">
                @foreach(session('hallazgos') as $h)<li>{{ $h }}</li>@endforeach
            </ul>
        @endif
    </flux:card>

    <!-- Log de actividad del sitio (lo envía el worker) -->
    <flux:card class="bg-slate-950/60 border border-slate-800 p-6 rounded-2xl mb-8">
        <div class="mb-4 pb-4 border-b border-slate-800">
            <flux:heading size="lg" class="text-slate-100 font-bold">🕵️ Actividad en el sitio</flux:heading>
            <flux:subheading class="text-slate-400 text-xs">Accesos, plugins, usuarios y ajustes críticos registrados por el worker</flux:subheading>
        </div>
        @if($actividad->isEmpty())
            <p class="text-sm text-slate-500">Aún no ha llegado actividad de este sitio.</p>
        @else
            <ul class="divide-y divide-slate-800/60 text-sm">
                @foreach($actividad as $a)
                    <li class="py-2.5 flex flex-wrap justify-between gap-2">
                        <span class="{{ $a->severidad === 'critical' ? 'text-rose-400 font-semibold' : 'text-slate-300' }}">
                            {{ $a->accion }}@if($a->objeto_nombre) · {{ $a->objeto_nombre }}@endif
                            <span class="text-xs text-slate-500">{{ $a->actor_login ?? 'desconocido' }}{{ $a->ip ? ' · ' . $a->ip : '' }}</span>
                        </span>
                        <span class="text-xs text-slate-500">{{ $a->ocurrido_en->diffForHumans() }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </flux:card>

    <!-- Section 5: Audit Log Events -->
    <flux:card class="bg-slate-950/60 border border-slate-800 p-6 rounded-2xl">
        <div class="flex items-center justify-between mb-6 pb-4 border-b border-slate-800">
            <flux:heading size="lg" class="text-slate-100 font-bold">📜 Historial de Operaciones del Sitio</flux:heading>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-300">
                <thead class="bg-slate-900/80 text-xs uppercase tracking-wider text-slate-400 border-b border-slate-800">
                    <tr>
                        <th class="py-3.5 px-4 font-semibold">Acción</th>
                        <th class="py-3.5 px-4 font-semibold">Mensaje</th>
                        <th class="py-3.5 px-4 font-semibold">Resultado</th>
                        <th class="py-3.5 px-4 font-semibold">Fecha</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    @forelse($eventos as $evento)
                        <tr class="hover:bg-slate-900/40">
                            <td class="py-3.5 px-4 font-semibold text-slate-200">
                                {{ \App\Webminder\Panel\Models\EventoAuditoria::getAccionLabel($evento->accion) }}
                            </td>
                            <td class="py-3.5 px-4 text-slate-300">{{ $evento->descripcion }}@if($evento->resultado !== 'success' && !empty($evento->detalles['error'])) <span class="block text-xs text-rose-400">{{ $evento->detalles['error'] }}</span>@endif</td>
                            <td class="py-3.5 px-4">
                                @if($evento->resultado === 'success')
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">ÉXITO</span>
                                @else
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-rose-500/10 text-rose-400 border border-rose-500/20">ERROR</span>
                                @endif
                            </td>
                            <td class="py-3.5 px-4 text-xs text-slate-400">{{ $evento->created_at->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-8 text-center text-slate-500">No hay eventos registrados para este sitio.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>

</x-layouts.panel>
