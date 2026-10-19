<?php

namespace App\Webminder\Panel\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Credencial extends Model
{
    use HasFactory;

    protected $table = 'panel_credenciales';

    protected $fillable = [
        'sitio_id',
        'client_id',
        'client_secret',
        'activa',
        'ultima_utilizacion',
        'notas',
    ];

    protected $hidden = [
        'client_secret',
    ];

    protected $casts = [
        'activa' => 'boolean',
        'ultima_utilizacion' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relación: Una credencial pertenece a un sitio
     */
    public function sitio(): BelongsTo
    {
        return $this->belongsTo(SitioWeb::class);
    }

    /**
     * Generar nuevo CLIENT_ID
     */
    public static function generarClientId(int $siteId): string
    {
        return 'webminder_' . $siteId . '_' . bin2hex(random_bytes(8));
    }

    /**
     * Generar nuevo CLIENT_SECRET
     */
    public static function generarClientSecret(): string
    {
        return 'sk_' . bin2hex(random_bytes(32));
    }

    /**
     * Registrar utilización
     */
    public function registrarUso(): void
    {
        $this->update(['ultima_utilizacion' => now()]);
    }
}
