<?php

namespace App\Webminder\Panel\Services;

use App\Webminder\Panel\Models\EstadoSitio;
use App\Webminder\Panel\Models\SitioWeb;
use Illuminate\Support\Facades\Http;

/**
 * Fallos conocidos de los plugins, temas y núcleo instalados, según el feed gratuito de Wordfence
 * Intelligence V3 (clave en WEBMINDER_WORDFENCE_CLAVE). El feed pesa más de 100 MB: se descarga una
 * vez al día a disco, se lee registro a registro y solo se guarda lo que afecta a algo instalado.
 */
class VulnerabilidadesService
{
    public const FEED = 'https://www.wordfence.com/api/intelligence/v3/vulnerabilities/scanner';

    private ?array $cargado = null;

    public static function indice(): string
    {
        return storage_path('app/vulnerabilidades.json');
    }

    /** Descarga el feed y rehace el índice con lo instalado en las webs. Devuelve cuántos fallos guardó. */
    public function actualizar(): int
    {
        $clave = config('services.wordfence.clave');
        if (!$clave) {
            throw new \RuntimeException('Falta WEBMINDER_WORDFENCE_CLAVE en el .env (clave gratuita de wordfence.com → Integrations).');
        }
        $tmp = storage_path('app/wordfence-feed.json');
        try {
            Http::withToken($clave)->timeout(600)->sink($tmp)->get(self::FEED)->throw();
            $instalado = $this->instalado();
            $indice = [];
            $leidos = 0;
            $this->recorrer($tmp, function (array $v) use ($instalado, &$indice, &$leidos) {
                $leidos++;
                foreach ($v['software'] ?? [] as $s) {
                    $clave = ($s['type'] ?? '') . ':' . ($s['slug'] ?? '');
                    if (isset($instalado[$clave])) {
                        $indice[$clave][] = [
                            'id' => $v['id'] ?? '',
                            'titulo' => $v['title'] ?? '',
                            'rangos' => array_values($s['affected_versions'] ?? []),
                            'arreglado' => $s['patched_versions'] ?? [],
                            'url' => $v['references'][0] ?? 'https://www.wordfence.com/threat-intel/vulnerabilities/id/' . ($v['id'] ?? ''),
                        ];
                    }
                }
            });
        } finally {
            @unlink($tmp);
        }
        if (!$leidos) { // feed vacío o cortado: mejor el índice de ayer que uno vacío que diga «todo bien»
            throw new \RuntimeException('El feed de Wordfence llegó vacío o no es JSON; se mantiene el índice anterior.');
        }
        file_put_contents(self::indice(), json_encode($indice));
        $this->cargado = null;

        return array_sum(array_map('count', $indice));
    }

    /**
     * Llama a $cada con cada registro del objeto raíz { "<uuid>": {...}, ... } sin decodificar el archivo
     * entero (cientos de MB en memoria): sigue la profundidad de llaves fuera de las cadenas.
     */
    public function recorrer(string $ruta, callable $cada): void
    {
        $f = fopen($ruta, 'rb');
        $prof = 0;
        $en_cadena = $escape = false;
        $registro = null;
        $inicio = 0;
        while (($trozo = fread($f, 1 << 20)) !== false && $trozo !== '') {
            $n = strlen($trozo);
            for ($i = 0; $i < $n; $i++) {
                $c = $trozo[$i];
                if ($en_cadena) {
                    if ($escape) {
                        $escape = false;
                    } elseif ($c === '\\') {
                        $escape = true;
                    } elseif ($c === '"') {
                        $en_cadena = false;
                    }
                } elseif ($c === '"') {
                    $en_cadena = true;
                } elseif ($c === '{' && ++$prof === 2) {
                    $registro = '';
                    $inicio = $i;
                } elseif ($c === '}' && $prof-- === 2) {
                    $v = json_decode($registro . substr($trozo, $inicio, $i - $inicio + 1), true);
                    is_array($v) && $cada($v);
                    $registro = null;
                }
            }
            if ($registro !== null) {
                $registro .= substr($trozo, $inicio);
                $inicio = 0;
            }
        }
        fclose($f);
    }

    /** 'plugin:slug' / 'theme:slug' / 'core:wordpress' => true, de todas las webs activas. */
    private function instalado(): array
    {
        $out = ['core:wordpress' => true];
        foreach (SitioWeb::where('activo', true)->get() as $sitio) {
            foreach ($this->componentes($sitio->estadoActual()) as $c) {
                $out[$c['clave']] = true;
            }
        }

        return $out;
    }

    /** Lo instalado en una web, con versión, según su último estado. */
    private function componentes(?EstadoSitio $estado): array
    {
        $d = $estado?->datos ?? [];
        $out = [];
        foreach ($d['plugins_list'] ?? [] as $p) {
            $f = (string) ($p['file'] ?? '');
            $slug = str_contains($f, '/') ? dirname($f) : basename($f, '.php');
            $out[] = ['clave' => "plugin:{$slug}", 'tipo' => 'Plugin', 'nombre' => $p['name'] ?? $slug, 'version' => (string) ($p['version'] ?? '')];
        }
        foreach ($d['themes'] ?? [] as $t) {
            $out[] = ['clave' => 'theme:' . ($t['slug'] ?? ''), 'tipo' => 'Tema', 'nombre' => $t['name'] ?? '', 'version' => (string) ($t['version'] ?? '')];
        }
        if (!empty($d['wp_version'])) {
            $out[] = ['clave' => 'core:wordpress', 'tipo' => 'WordPress', 'nombre' => 'WordPress', 'version' => (string) $d['wp_version']];
        }

        return $out;
    }

    /** Fallos que afectan a la versión instalada en esta web. */
    public function deSitio(SitioWeb $sitio): array
    {
        $indice = $this->cargado ??= is_file(self::indice()) ? (json_decode((string) file_get_contents(self::indice()), true) ?: []) : [];
        $out = [];
        foreach ($this->componentes($sitio->estadoActual()) as $c) {
            foreach ($indice[$c['clave']] ?? [] as $v) {
                if ($c['version'] !== '' && self::afecta($c['version'], $v['rangos'])) {
                    $out[] = $c + $v;
                }
            }
        }

        return $out;
    }

    public static function afecta(string $version, array $rangos): bool
    {
        foreach ($rangos as $r) {
            $desde = (string) ($r['from_version'] ?? '*');
            $hasta = (string) ($r['to_version'] ?? '*');
            if (($desde === '*' || version_compare($version, $desde, !empty($r['from_inclusive']) ? '>=' : '>'))
                && ($hasta === '*' || version_compare($version, $hasta, !empty($r['to_inclusive']) ? '<=' : '<'))) {
                return true;
            }
        }

        return false;
    }
}
