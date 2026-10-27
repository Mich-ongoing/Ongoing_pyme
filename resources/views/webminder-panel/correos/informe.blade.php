@php
    // Correo: estilos en línea y tablas, que es lo que respetan Gmail y Outlook
    $atencion = $filas->filter(fn ($f) => $f['e'] && $f['e']->estado !== 'completada');
    $bien = $filas->filter(fn ($f) => $f['e'] && $f['e']->estado === 'completada');
    $alDia = $filas->filter(fn ($f) => !$f['e']);
    $aplicadas = $filas->sum(fn ($f) => $f['e'] ? collect($f['e']->items)->whereIn('estado', ['ok', 'ok_sin_verificar'])->count() : 0);
    $color = fn ($estado) => match ($estado) {
        'ok', 'ok_sin_verificar' => ['#e7f6ec', '#1e7b3c'],
        'cambio_visual' => ['#fff4e0', '#9a5b00'],
        'revertido', 'licencia', 'error' => ['#fdecec', '#b42318'],
        default => ['#f0f1f3', '#5b6170'],
    };
    $lote = [
        'con_errores' => ['Con errores', '#fdecec', '#b42318'],
        'interrumpida' => ['Cortado', '#fff4e0', '#9a5b00'],
        'completada' => ['Sin incidencias', '#e7f6ec', '#1e7b3c'],
    ];
    $fecha = \Illuminate\Support\Carbon::parse($ronda)->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY');
@endphp
<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light"></head>
<body style="margin:0;padding:0;background:#f3f4f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1f2330;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f7;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;">

    {{-- Cabecera --}}
    <tr><td style="background:#1f2a44;border-radius:12px 12px 0 0;padding:24px 28px;">
        <div style="font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#9fb0d6;">watchWP</div>
        <div style="font-size:22px;font-weight:700;color:#ffffff;margin-top:4px;">Informe de mantenimiento</div>
        <div style="font-size:14px;color:#c9d3ea;margin-top:4px;">{{ ucfirst($fecha) }} · ronda de las {{ substr($ronda, 11) }}</div>
    </td></tr>

    {{-- Resumen --}}
    <tr><td style="background:#ffffff;padding:20px 20px 8px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
            @foreach ([
                [$filas->count(), 'sitios', '#1f2330'],
                [$aplicadas, 'actualizaciones', '#1e7b3c'],
                [$atencion->count(), 'con avisos', $atencion->count() ? '#b42318' : '#1e7b3c'],
                [$caidas->count(), 'caídas', $caidas->count() ? '#b42318' : '#1e7b3c'],
            ] as [$n, $txt, $c])
                <td width="25%" align="center" style="padding:4px;">
                    <div style="background:#f7f8fa;border-radius:10px;padding:12px 4px;">
                        <div style="font-size:26px;font-weight:700;color:{{ $c }};">{{ $n }}</div>
                        <div style="font-size:12px;color:#5b6170;">{{ $txt }}</div>
                    </div>
                </td>
            @endforeach
        </tr></table>
    </td></tr>

    @if ($caidas->isNotEmpty())
        <tr><td style="background:#ffffff;padding:12px 28px 0;">
            <div style="background:#fdecec;border-left:4px solid #b42318;border-radius:6px;padding:12px 14px;">
                <div style="font-weight:700;color:#b42318;margin-bottom:4px;">🔴 Webs caídas</div>
                @foreach ($caidas as $s)
                    <div style="font-size:14px;">{{ $s->nombre }} <span style="color:#5b6170;">desde el {{ \App\Webminder\Panel\Services\AvisosService::hora($s->caida_desde) }}</span></div>
                @endforeach
            </div>
        </td></tr>
    @endif

    @foreach ([['Necesitan atención', $atencion], ['Actualizadas sin incidencias', $bien]] as [$titulo, $grupo])
        @continue($grupo->isEmpty())
        <tr><td style="background:#ffffff;padding:20px 28px 4px;">
            <div style="font-size:16px;font-weight:700;border-bottom:1px solid #e6e8ee;padding-bottom:8px;">{{ $titulo }} <span style="color:#5b6170;font-weight:400;">({{ $grupo->count() }})</span></div>
        </td></tr>
        @foreach ($grupo as $f)
            @php([$etiqueta, $fondo, $tinta] = $lote[$f['e']->estado] ?? [str_replace('_', ' ', $f['e']->estado), '#f0f1f3', '#5b6170'])
            <tr><td style="background:#ffffff;padding:8px 28px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e6e8ee;border-radius:10px;">
                    <tr><td style="padding:12px 14px;">
                        <span style="font-size:15px;font-weight:700;">{{ $f['sitio']->nombre }}</span>
                        <span style="display:inline-block;margin-left:6px;background:{{ $fondo }};color:{{ $tinta }};font-size:12px;font-weight:600;border-radius:999px;padding:2px 9px;">{{ $etiqueta }}</span>
                        @if ($f['mb'] !== null)
                            <span style="font-size:12px;color:#8a90a0;margin-left:6px;">copias: {{ $f['mb'] }} MB</span>
                        @endif
                        @if ($f['e']->error)
                            <div style="font-size:13px;color:#b42318;margin-top:6px;">{{ $f['e']->error }}</div>
                        @endif
                        @if ($f['e']->items)
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:8px;">
                                @foreach ($f['e']->items as $item)
                                    @php([$bg, $fg] = $color($item['estado']))
                                    <tr>
                                        <td style="padding:5px 0;border-top:1px solid #f0f1f3;font-size:13px;">
                                            {{ $item['nombre'] }}
                                            <span style="color:#8a90a0;">{{ $item['tipo'] === 'translation' ? "{$item['hasta']} paquetes" : "{$item['desde']} → {$item['hasta']}" }}</span>
                                            @if (!in_array($item['estado'], ['ok', 'pendiente'], true) && $item['mensaje'])
                                                <div style="font-size:12px;color:#5b6170;">{{ $item['mensaje'] }}</div>
                                            @endif
                                        </td>
                                        <td align="right" valign="top" style="padding:5px 0 5px 8px;border-top:1px solid #f0f1f3;white-space:nowrap;">
                                            <span style="background:{{ $bg }};color:{{ $fg }};font-size:11px;font-weight:600;border-radius:999px;padding:2px 8px;">{{ trim(str_replace(['✖', '⚠', ', revisa las capturas'], '', $estados[$item['estado']] ?? $item['estado'])) }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </table>
                            <a href="{{ route('webminder-panel.ejecuciones.show', $f['e']) }}" style="display:inline-block;margin-top:8px;font-size:13px;color:#2f5bd3;text-decoration:none;font-weight:600;">Ver el lote →</a>
                        @endif
                    </td></tr>
                </table>
            </td></tr>
        @endforeach
    @endforeach

    @if ($alDia->isNotEmpty())
        <tr><td style="background:#ffffff;padding:20px 28px 4px;">
            <div style="font-size:16px;font-weight:700;border-bottom:1px solid #e6e8ee;padding-bottom:8px;">Ya estaban al día <span style="color:#5b6170;font-weight:400;">({{ $alDia->count() }})</span></div>
            <div style="font-size:13px;color:#5b6170;line-height:1.7;margin-top:8px;">
                @foreach ($alDia as $f)
                    <span style="display:inline-block;background:#f0f1f3;border-radius:999px;padding:1px 9px;margin:2px 2px;">✔ {{ $f['sitio']->nombre }}@if ($f['mb'] !== null) · {{ $f['mb'] }} MB @endif</span>
                @endforeach
            </div>
        </td></tr>
    @endif

    @if ($filas->isEmpty())
        <tr><td style="background:#ffffff;padding:20px 28px;font-size:14px;color:#5b6170;">No hay sitios vinculados.</td></tr>
    @endif

    {{-- Pie --}}
    <tr><td style="background:#ffffff;border-radius:0 0 12px 12px;padding:20px 28px 24px;">
        <a href="{{ route('webminder-panel.dashboard') }}" style="display:inline-block;background:#1f2a44;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;border-radius:8px;padding:10px 18px;">Abrir el panel</a>
    </td></tr>
    <tr><td align="center" style="padding:14px;font-size:12px;color:#8a90a0;">watchWP · mantenimiento automático de Ongoing</td></tr>

</table>
</td></tr>
</table>
</body>
</html>
