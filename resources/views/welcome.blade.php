<!DOCTYPE html>
<html lang="es" class="h-full bg-slate-950 text-slate-100 dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Webminder Panel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 font-sans antialiased flex items-center justify-center p-4 relative overflow-hidden">
    <!-- Ambient Background Blur Elements -->
    <div class="absolute -top-40 -left-40 w-96 h-96 bg-indigo-600/20 rounded-full blur-3xl pointer-events-none"></div>
    <div class="absolute -bottom-40 -right-40 w-96 h-96 bg-indigo-500/15 rounded-full blur-3xl pointer-events-none"></div>

    <div class="w-full max-w-md relative z-10">
        <!-- Logo & Header -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-tr from-indigo-600 to-indigo-500 text-3xl text-white shadow-xl shadow-indigo-600/30 mb-4">
                🛡️
            </div>
            <flux:heading size="xl" class="text-white font-bold tracking-tight">Webminder Panel</flux:heading>
            <flux:subheading class="text-slate-400 mt-1">Acceso restringido — Inicia sesión para continuar</flux:subheading>
        </div>

        <!-- Login Card -->
        <flux:card class="bg-slate-900/80 backdrop-blur-xl border border-slate-800 shadow-2xl p-8 rounded-2xl">
            @if ($errors->any())
                <div class="bg-rose-500/10 border border-rose-500/20 text-rose-400 p-4 rounded-xl text-sm mb-6">
                    <ul class="space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form action="{{ route('login.post') }}" method="POST" class="space-y-5">
                @csrf

                <flux:field>
                    <flux:label class="text-slate-300">Correo Electrónico</flux:label>
                    <flux:input 
                        type="email" 
                        name="email" 
                        value="{{ old('email') }}" 
                        placeholder="michell@ongoing.es" 
                        required 
                        autofocus
                        class="bg-slate-950/60 border-slate-800 text-white placeholder-slate-500"
                    />
                </flux:field>

                <flux:field>
                    <flux:label class="text-slate-300">Contraseña</flux:label>
                    <flux:input 
                        type="password" 
                        name="password" 
                        placeholder="••••••••" 
                        required
                        class="bg-slate-950/60 border-slate-800 text-white placeholder-slate-500"
                    />
                </flux:field>

                <div class="flex items-center justify-between pt-1">
                    <flux:checkbox name="remember" id="remember" label="Recordarme" class="text-slate-400" />
                </div>

                <flux:button type="submit" variant="primary" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-3 rounded-xl shadow-lg shadow-indigo-600/30 transition-all cursor-pointer">
                    Iniciar Sesión
                </flux:button>
            </form>
        </flux:card>

        <p class="text-center text-xs text-slate-500 mt-8">
            &copy; {{ date('Y') }} Ongoing Digital &bull; Panel de Administración Protegido
    </div>
</body>
</html>
