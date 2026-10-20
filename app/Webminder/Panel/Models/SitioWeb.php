<?php

namespace App\Webminder\Panel\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SitioWeb extends Model
{
    use HasFactory;

    protected $table = 'panel_sitios_web';

    protected $fillable = [
        'cliente_id',
        'nombre',
        'url',
        'descripcion',
        'tecnologia',
        'version_php',
        'version_laravel',
        'activo',
        'estado',
        'ultima_verificacion',
        'proxima_verificacion',
        'caida_desde',
        'tiene_injerto',
        'dominios_scripts',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'ultima_verificacion' => 'datetime',
        'proxima_verificacion' => 'datetime',
        'caida_desde' => 'datetime',
        'tiene_injerto' => 'boolean',
        'dominios_scripts' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relación: Un sitio pertenece a un cliente
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /**
     * Relación: Un sitio tiene una credencial
     */
    public function credencial(): HasOne
    {
        return $this->hasOne(Credencial::class, 'sitio_id');
    }

    /**
     * Relación: Un sitio tiene muchos eventos
     */
    public function eventos(): HasMany
    {
        return $this->hasMany(EventoAuditoria::class, 'sitio_id');
    }

    /**
     * Relación: Historial de estados
     */
    public function estadosHistorico(): HasMany
    {
        return $this->hasMany(EstadoSitio::class, 'sitio_id');
    }

    /**
     * Obtener estado actual
     */
    public function estadoActual(): ?EstadoSitio
    {
        return $this->estadosHistorico()->latest()->first();
    }

    /**
     * ¿Está el sitio en línea?
     */
    public function estaEnLinea(): bool
    {
        return $this->estado === 'online';
    }

    /**
     * ¿Necesita mantenimiento?
     */
    public function enMantenimiento(): bool
    {
        $estado = $this->estadoActual();
        return $estado?->datos['maintenance_mode'] ?? false;
    }
}
