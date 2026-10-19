<?php

namespace App\Webminder\Panel\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventoAuditoria extends Model
{
    use HasFactory;

    protected $table = 'panel_eventos_auditoria';

    protected $fillable = [
        'cliente_id',
        'sitio_id',
        'usuario_id',
        'accion',
        'descripcion',
        'detalles',
        'resultado',
        'ip_origen',
    ];

    protected $casts = [
        'detalles' => 'json',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relación: Un evento pertenece a un cliente
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /**
     * Relación: Un evento pertenece a un sitio (opcional)
     */
    public function sitio(): BelongsTo
    {
        return $this->belongsTo(SitioWeb::class);
    }

    /**
     * Acciones disponibles
     */
    public const ACCIONES = [
        'status_check' => 'Verificación de estado',
        'sync' => 'Sincronización',
        'maintenance_on' => 'Modo mantenimiento activado',
        'maintenance_off' => 'Modo mantenimiento desactivado',
        'db_clean' => 'Limpieza de BD',
        'db_optimize' => 'Optimización de BD',
        'credentials_generated' => 'Credenciales generadas',
        'credentials_rotated' => 'Credenciales rotadas',
        'sitio_conectado' => 'Sitio conectado',
        'sitio_desconectado' => 'Sitio desconectado',
    ];

    /**
     * Obtener descripción de acción
     */
    public static function getAccionLabel(string $accion): string
    {
        return self::ACCIONES[$accion] ?? $accion;
    }

    /**
     * Registrar evento exitoso
     */
    public static function registrarExito(string $accion, string $descripcion, $sitioId = null, $detalles = []): void
    {
        $clienteId = null;
        if ($sitioId) {
            $sitio = SitioWeb::find($sitioId);
            $clienteId = $sitio?->cliente_id;
        }

        self::create([
            'cliente_id' => $clienteId,
            'sitio_id' => $sitioId,
            'accion' => $accion,
            'descripcion' => $descripcion,
            'resultado' => 'success',
            'detalles' => $detalles,
            'ip_origen' => request()->ip() ?? '127.0.0.1',
        ]);
    }

    /**
     * Registrar evento fallido
     */
    public static function registrarError(string $accion, string $descripcion, string $error, $sitioId = null, $detalles = []): void
    {
        $clienteId = null;
        if ($sitioId) {
            $sitio = SitioWeb::find($sitioId);
            $clienteId = $sitio?->cliente_id;
        }

        self::create([
            'cliente_id' => $clienteId,
            'sitio_id' => $sitioId,
            'accion' => $accion,
            'descripcion' => $descripcion,
            'resultado' => 'error',
            'detalles' => array_merge($detalles, ['error' => $error]),
            'ip_origen' => request()->ip() ?? '127.0.0.1',
        ]);
    }
}
