<!DOCTYPE html>
<html lang="es" class="h-full bg-neutral-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Facturación' }} · Ongoing</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-neutral-50 text-neutral-900 font-sans antialiased">
    <header class="bg-white border-b border-neutral-200">
        <div class="max-w-6xl mx-auto px-6 h-16 flex items-center justify-between gap-6">
            <div class="flex items-center gap-3">
                <span class="w-8 h-8 rounded-lg bg-neutral-900 text-white grid place-items-center text-sm font-semibold">O</span>
                <span class="font-semibold tracking-tight">Ongoing</span>
                <span class="text-neutral-300">/</span>
                <span class="text-neutral-500">Facturación</span>
            </div>
            <div class="flex items-center gap-5 text-sm">
                @if(Auth::user()->role === 'admin')
                    <a href="{{ route('webminder-panel.dashboard') }}" class="text-neutral-500 hover:text-neutral-900">Volver al panel</a>
                @endif
                <span class="text-neutral-500 hidden sm:inline">{{ Auth::user()->name }}</span>
                <form action="{{ route('logout') }}" method="POST" class="m-0">
                    @csrf
                    <button type="submit" class="text-neutral-500 hover:text-neutral-900">Salir</button>
                </form>
            </div>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-6 py-10">
        @if (session('success'))
            <div class="mb-6 px-4 py-3 rounded-lg bg-white border border-neutral-200 text-sm text-neutral-900">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="mb-6 px-4 py-3 rounded-lg bg-white border border-neutral-900 text-sm text-neutral-900 whitespace-pre-line">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="mb-6 px-4 py-3 rounded-lg bg-white border border-neutral-900 text-sm">{{ $errors->first() }}</div>
        @endif

        {{ $slot }}
    </main>
</body>
</html>
