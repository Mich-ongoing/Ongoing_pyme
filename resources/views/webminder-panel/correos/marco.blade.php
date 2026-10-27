{{-- Marco común de los correos de watchWP: estilos en línea y tablas, que es lo que respetan Gmail y Outlook --}}
<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light"></head>
<body style="margin:0;padding:0;background:#f3f4f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1f2330;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f7;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;">

    <tr><td style="background:#1f2a44;border-top:5px solid {{ $acento ?? '#1f2a44' }};border-radius:12px 12px 0 0;padding:24px 28px;">
        <div style="font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#9fb0d6;">watchWP</div>
        <div style="font-size:22px;font-weight:700;color:#ffffff;margin-top:4px;">{{ $titulo }}</div>
        <div style="font-size:14px;color:#c9d3ea;margin-top:4px;">{{ $subtitulo }}</div>
    </td></tr>

@yield('contenido')

    <tr><td style="background:#ffffff;border-radius:0 0 12px 12px;padding:20px 28px 24px;">
        <a href="{{ route('webminder-panel.dashboard') }}" style="display:inline-block;background:#1f2a44;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;border-radius:8px;padding:10px 18px;">Abrir el panel</a>
    </td></tr>
    <tr><td align="center" style="padding:14px;font-size:12px;color:#8a90a0;">watchWP · mantenimiento automático de Ongoing</td></tr>

</table>
</td></tr>
</table>
</body>
</html>
