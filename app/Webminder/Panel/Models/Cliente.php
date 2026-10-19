<?php

namespace App\Webminder\Panel\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cliente extends Model
{
    use HasFactory;

    protected $table = 'panel_clientes';

    protected $fillable = [
        'nombre',
        'empresa',
        'email',
        'telefono',
        'ciudad',
        'pais',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relación: Un cliente tiene muchos sitios web
     */
    public function sitios(): HasMany
    {
        return $this->hasMany(SitioWeb::class, 'cliente_id');
    }

    /**
     * Relación: Un cliente tiene muchos eventos de auditoría
     */
    public function eventos(): HasMany
    {
        return $this->hasMany(EventoAuditoria::class, 'cliente_id');
    }

    /**
     * Contar sitios activos
     */
    public function sitiosActivos(): int
    {
        return $this->sitios()->where('activo', true)->count();
    }

    /**
     * Obtener última fecha de sincronización
     */
    public function ultimaSincronizacion()
    {
        return $this->eventos()
            ->whereIn('accion', ['status_check', 'sync'])
            ->latest()
            ->first()?->created_at;
    }
}
