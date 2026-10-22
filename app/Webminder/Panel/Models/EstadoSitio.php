<?php

namespace App\Webminder\Panel\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstadoSitio extends Model
{
    use HasFactory;

    protected $table = 'panel_estados_sitio';

    protected $fillable = [
        'sitio_id',
        'estado',
        'datos',
        'metricas',
        'codigo_http',
        'tiempo_respuesta_ms',
    ];

    protected $casts = [
        'datos' => 'json',
        'metricas' => 'json',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relación: Un estado pertenece a un sitio
     */
    public function sitio(): BelongsTo
    {
        return $this->belongsTo(SitioWeb::class);
    }

    /**
     * ¿La BD está conectada?
     */
    public function baseDatosConectada(): bool
    {
        return $this->datos['database']['connected'] ?? false;
    }

    /**
     * Obtener tamaño de BD
     */
    public function tamañoBD(): float
    {
        return $this->datos['database']['size_mb'] ?? 0;
    }

    /**
     * Obtener uso de memoria
     */
    public function usoMemoria(): float
    {
        return $this->datos['app']['memory_usage']['current_mb'] ?? 0;
    }

    /**
     * ¿Caché funcionando?
     */
    public function cacheFuncionando(): bool
    {
        return $this->datos['cache']['working'] ?? false;
    }

    /**
     * Obtener auditoría de seguridad
     */
    public function seguridad(): array
    {
        $auditoria = $this->datos['security_audit'] ?? ['score' => 100, 'checks' => []];
        if ($php = $this->datos['php_version'] ?? null) {
            array_unshift($auditoria['checks'], $this->phpSinSoporte() ? [
                'id' => 'php', 'label' => 'Versión de PHP', 'status' => 'danger',
                'message' => "PHP {$php} ya no recibe parches de seguridad. Pide al hosting subir a PHP " . self::PHP_MINIMO . ' o superior.',
            ] : [
                'id' => 'php', 'label' => 'Versión de PHP', 'status' => 'pass',
                'message' => "PHP {$php}.",
            ]);
        }
        $auditoria['score'] = $this->puntuacionSeguridad();

        return $auditoria;
    }

    /** Por debajo de esta versión de PHP la web es «Riesgo alto» aunque lo demás esté bien. */
    public const PHP_MINIMO = '8.1';

    public function phpSinSoporte(): bool
    {
        $php = $this->datos['php_version'] ?? null;

        return $php && version_compare($php, self::PHP_MINIMO, '<');
    }

    /**
     * Puntuación de seguridad (0-100)
     */
    public function puntuacionSeguridad(): int
    {
        $score = $this->datos['security_audit']['score'] ?? 100;

        // < 50 es lo que las vistas pintan como «Riesgo»
        return $this->phpSinSoporte() ? min($score, 49) : $score;
    }

    /**
     * Último envío de correo de la web (worker ≥ 2.8): ultimo_ok, ultimo_error, error, via.
     */
    public function correo(): ?array
    {
        return $this->datos['correo'] ?? null;
    }

    /**
     * Obtener usuarios administradores
     */
    public function usuariosAdmin(): array
    {
        return $this->datos['admin_users'] ?? [];
    }

    /**
     * Obtener lista de plugins
     */
    public function plugins(): array
    {
        return $this->datos['plugins_list'] ?? [];
    }

    /**
     * Obtener lista de temas
     */
    public function temas(): array
    {
        return $this->datos['themes'] ?? [];
    }

    /**
     * Obtener información de entorno (PHP, DB, etc.)
     */
    public function entorno(): array
    {
        return $this->datos['environment'] ?? [];
    }

    /**
     * Resumen de salud del sitio
     */
    public function resumenSalud(): array
    {
        return $this->datos['site_health_summary'] ?? [
            'good' => 0,
            'recommended' => 0,
            'critical' => 0,
        ];
    }
}
