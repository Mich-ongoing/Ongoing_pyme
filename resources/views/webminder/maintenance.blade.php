<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>En mantenimiento</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #334155;
        }

        .container {
            max-width: 600px;
            padding: 40px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            text-align: center;
        }

        svg {
            width: 80px;
            height: 80px;
            margin-bottom: 24px;
            color: #667eea;
            animation: spin 3s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        h1 {
            font-size: 28px;
            margin-bottom: 12px;
            color: #0f172a;
            font-weight: 700;
        }

        .subtitle {
            font-size: 16px;
            line-height: 1.6;
            color: #64748b;
            margin-bottom: 8px;
        }

        .timestamp {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid #e2e8f0;
        }

        .brand {
            font-size: 12px;
            color: #cbd5e1;
            margin-top: 16px;
        }

        .brand a {
            color: #667eea;
            text-decoration: none;
        }

        .brand a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
        </svg>

        <h1>En mantenimiento</h1>
        <p class="subtitle">Estamos haciendo algunos ajustes para mejorar tu experiencia.</p>
        <p class="subtitle">Volvemos en breve.</p>

        <div class="timestamp">
            {{ now()->setTimezone(config('app.timezone'))->format('H:i:s') }}
        </div>

        <div class="brand">
            Gestionado por <a href="{{ config('webminder.brand_url', '#') }}" target="_blank">{{ config('webminder.brand_name', 'Webminder') }}</a>
        </div>
    </div>
</body>
</html>
