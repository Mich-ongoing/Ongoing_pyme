<!DOCTYPE html>
<html lang="es" class="h-full bg-slate-900 text-slate-100 dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Webminder Panel' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-900 text-slate-100 font-sans antialiased flex">

    <!-- Sidebar Navigation -->
    <aside class="w-64 bg-slate-950 border-r border-slate-800 flex flex-col justify-between p-4 shrink-0 min-h-screen">
        <div>
            <!-- Brand Logo -->
            <div class="flex items-center gap-3 px-3 py-4 mb-6 border-b border-slate-800/80">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-indigo-500 flex items-center justify-center text-white font-bold text-xl shadow-lg shadow-indigo-500/25">
                    🛡️
                </div>
                <div>
                    <h1 class="font-bold text-slate-100 text-lg leading-none">Webminder</h1>
                    <span class="text-[10px] text-indigo-400 font-semibold tracking-wider uppercase">Panel Central</span>
                </div>
            </div>

            <!-- Navigation Links -->
            <nav class="space-y-1.5">
                <a href="/webminder-panel" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-sm font-medium transition-all {{ request()->is('webminder-panel') ? 'bg-indigo-600 text-white font-semibold shadow-md shadow-indigo-600/30' : 'text-slate-400 hover:bg-slate-900 hover:text-slate-200' }}">
                    <span class="text-base">📊</span>
                    <span>Dashboard</span>
                </a>
                <a href="/webminder-panel/sitios" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-sm font-medium transition-all {{ request()->is('webminder-panel/sitios*') ? 'bg-indigo-600 text-white font-semibold shadow-md shadow-indigo-600/30' : 'text-slate-400 hover:bg-slate-900 hover:text-slate-200' }}">
                    <span class="text-base">🌐</span>
                    <span>Sitios Web</span>
                </a>
                <a href="/webminder-panel/clientes" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-sm font-medium transition-all {{ request()->is('webminder-panel/clientes*') ? 'bg-indigo-600 text-white font-semibold shadow-md shadow-indigo-600/30' : 'text-slate-400 hover:bg-slate-900 hover:text-slate-200' }}">
                    <span class="text-base">🏢</span>
                    <span>Clientes</span>
                </a>
<a href="/webminder-panel/injerto" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-sm font-medium transition-all {{ request()->is('webminder-panel/injerto*') ? 'bg-indigo-600 text-white font-semibold shadow-md shadow-indigo-600/30' : 'text-slate-400 hover:bg-slate-900 hover:text-slate-200' }}">
                    <span class="text-base">💉</span>
                    <span>Injerto</span>
                </a>
                <a href="/webminder-panel/cerrojo" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-sm font-medium transition-all {{ request()->is('webminder-panel/cerrojo*') ? 'bg-indigo-600 text-white font-semibold shadow-md shadow-indigo-600/30' : 'text-slate-400 hover:bg-slate-900 hover:text-slate-200' }}">
                    <span class="text-base">🔒</span>
                    <span>Cerrojo</span>
                </a>
                                <a href="/webminder-panel/auditoria" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-sm font-medium transition-all {{ request()->is('webminder-panel/auditoria*') ? 'bg-indigo-600 text-white font-semibold shadow-md shadow-indigo-600/30' : 'text-slate-400 hover:bg-slate-900 hover:text-slate-200' }}">
                    <span class="text-base">📜</span>
                    <span>Auditoría</span>
                </a>
            </nav>
        </div>

        <!-- User Profile & Logout -->
        @auth
        <div class="border-t border-slate-800/80 pt-4 mt-auto">
            <div class="flex items-center justify-between p-2.5 rounded-xl bg-slate-900/80 border border-slate-800">
                <div class="overflow-hidden mr-2">
                    <p class="text-xs font-semibold text-slate-200 truncate">{{ Auth::user()->name }}</p>
                    <p class="text-[11px] text-slate-400 truncate">{{ Auth::user()->email }}</p>
                </div>
                <form action="{{ route('logout') }}" method="POST" class="shrink-0 m-0">
                    @csrf
                    <button type="submit" title="Cerrar Sesión" class="p-2 text-rose-400 hover:bg-rose-500/10 hover:text-rose-300 rounded-lg transition">
                        🚪
                    </button>
                </form>
            </div>
        </div>
        @endauth
    </aside>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col min-w-0 bg-slate-900 overflow-y-auto">
        <!-- Top Header Bar -->
        <header class="bg-slate-950/70 backdrop-blur-md border-b border-slate-800/80 px-8 py-4 flex justify-between items-center sticky top-0 z-10">
            <div>
                <h2 class="text-lg font-bold text-slate-100">{{ $headerTitle ?? 'Panel de Control Centralizado' }}</h2>
                <p class="text-xs text-slate-400">{{ $headerSubtitle ?? 'Monitoreo y gestión remota de sitios web' }}</p>
            </div>
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span> Sistema Activo
                </span>
            </div>
        </header>

        <!-- Main Slot Content -->
        <main class="p-8 flex-1">
            @if (session('success'))
                <div class="mb-6 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-sm flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span>✅</span>
                        <span>{{ session('success') }}</span>
                    </div>
                </div>
            @endif

            @if (session('error'))
                <div class="mb-6 p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-400 text-sm flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span>⚠️</span>
                        <span>{{ session('error') }}</span>
                    </div>
                </div>
            @endif

            {{ $slot }}
        </main>
    </div>
</body>
</html>
