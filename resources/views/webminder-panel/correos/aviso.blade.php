@php
    // Aviso suelto: el color y el título salen del emoji del asunto; el texto plano se trocea en
    // párrafos, enlaces (una línea que es solo una URL), bloques sangrados y secciones «=== … ===» de código.
    $acento = match (true) {
        str_starts_with($asunto, '🔴') => '#d92d20',
        str_starts_with($asunto, '🟢') => '#1e9e4a',
        str_starts_with($asunto, '⚠') => '#e0a100',
        default => '#4a6cd4',
    };
    $titulo = trim(preg_replace('/^(🔴|🟢|⚠)\s*/u', '', $asunto));
    $subtitulo = \App\Webminder\Panel\Services\AvisosService::hora(now());
    $bloques = [];
    $codigo = false; // tras el primer «=== … ===» todo es código (Injerto), aunque traiga líneas en blanco
    $suelto = [];
    $volcar = function () use (&$suelto, &$bloques, &$codigo) {
        if ($suelto) {
            $bloques[] = [$codigo || str_starts_with($suelto[0], '  ') ? 'pre' : 'p', implode("\n", $suelto)];
        }
        $suelto = [];
    };
    foreach (preg_split('/\n{2,}/', trim($texto)) as $parrafo) {
        foreach (explode("\n", $parrafo) as $linea) {
            if (preg_match('/^=== (.+) ===$/', $linea, $m)) {
                $volcar();
                $codigo = true;
                $bloques[] = ['seccion', $m[1]];
            } elseif (!$codigo && preg_match('#^\s*(https?://\S+)\s*$#', $linea, $m)) {
                $volcar();
                $bloques[] = ['enlace', $m[1]];
            } else {
                $suelto[] = $linea;
            }
        }
        $volcar();
    }
    $enlace = fn ($url) => match (true) {
        str_contains($url, '/injerto/') => 'Revisar y aprobar',
        str_contains($url, '/injerto') => 'Ver en Injerto',
        str_contains($url, '/ejecuciones/') => 'Ver el lote',
        default => 'Abrir',
    };
@endphp
@extends('webminder-panel.correos.marco')

@section('contenido')
    <tr><td style="background:#ffffff;padding:22px 28px 4px;font-size:15px;line-height:1.55;">
        @foreach ($bloques as [$tipo, $valor])
            @if ($tipo === 'p')
                <p style="margin:0 0 14px;">{!! nl2br(e($valor)) !!}</p>
            @elseif ($tipo === 'pre')
                <pre style="margin:0 0 14px;background:#f5f6f9;border:1px solid #e6e8ee;border-radius:8px;padding:10px 12px;font-family:SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;line-height:1.5;white-space:pre-wrap;word-break:break-all;color:#2b3040;">{{ $valor }}</pre>
            @elseif ($tipo === 'seccion')
                <div style="margin:18px 0 6px;font-size:13px;font-weight:700;color:#5b6170;text-transform:uppercase;letter-spacing:.04em;">{{ $valor }}</div>
            @else
                <p style="margin:4px 0 16px;"><a href="{{ $valor }}" style="display:inline-block;background:#2f5bd3;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;border-radius:8px;padding:9px 16px;">{{ $enlace($valor) }} →</a></p>
            @endif
        @endforeach
    </td></tr>
@endsection
