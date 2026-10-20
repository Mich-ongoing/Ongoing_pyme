<?php

namespace App\Webminder\Panel\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ejecucion extends Model
{
    protected $table = 'panel_ejecuciones';

    protected $guarded = ['id'];

    protected $casts = [
        'copia_bd' => 'boolean',
        'items' => 'array',
        'iniciada_en' => 'datetime',
        'terminada_en' => 'datetime',
    ];

    public function sitio(): BelongsTo
    {
        return $this->belongsTo(SitioWeb::class, 'sitio_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /** Ítem de un lote a partir de una entrada de /updates del worker. */
    public static function item(array $u): array
    {
        return [
            'tipo' => $u['type'], 'item' => $u['item'], 'nombre' => $u['name'] ?? $u['item'],
            'desde' => $u['current_version'] ?? null, 'hasta' => $u['version'] ?? null,
            'estado' => 'pendiente', 'mensaje' => null,
        ];
    }

    public function terminada(): bool
    {
        return in_array($this->estado, ['completada', 'con_errores', 'interrumpida'], true);
    }

    /** Guarda el resultado de un ítem (se persiste al momento: la página de progreso lo lee). */
    public function marcarItem(int $i, string $estado, ?string $mensaje = null, array $extra = []): void
    {
        $items = $this->items;
        $items[$i] = array_merge($items[$i], $extra, ['estado' => $estado, 'mensaje' => $mensaje]);
        $this->update(['items' => $items]);
    }
}
