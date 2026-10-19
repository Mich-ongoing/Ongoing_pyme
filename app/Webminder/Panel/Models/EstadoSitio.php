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
        return $this->datos['security_audit'] ?? [
            'score' => 100,
            'checks' => [],
        ];
    }

    /**
     * Puntuación de seguridad (0-100)
     */
    public function puntuacionSeguridad(): int
    {
        return $this->datos['security_audit']['score'] ?? 100;
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
