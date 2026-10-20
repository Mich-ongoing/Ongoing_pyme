<?php

namespace App\Webminder\Panel\Models;

use App\Models\User;
use App\Webminder\Panel\Services\AvisosService;
use App\Webminder\Panel\Services\RondaNocturna;
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

    /**
     * Ítems de un lote a partir de entradas de /updates del worker. Traducciones al
     * final: si algo se revierte, que no hayan cambiado ya los idiomas.
     */
    public static function items(array $pendientes): array
    {
        return collect($pendientes)->map(fn ($u) => [
            'tipo' => $u['type'], 'item' => $u['item'], 'nombre' => $u['name'] ?? $u['item'],
            'desde' => $u['current_version'] ?? null, 'hasta' => $u['version'] ?? null,
            'estado' => 'pendiente', 'mensaje' => null,
        ])->sortBy(fn ($i) => $i['tipo'] === 'translation')->values()->all();
    }

    public function terminada(): bool
    {
        return in_array($this->estado, ['completada', 'con_errores', 'interrumpida'], true);
    }

    /** Cierra el lote; si es de la ronda nocturna, pasa al siguiente sitio. */
    public function terminar(string $estado, ?string $error = null): void
    {
        $this->update(['estado' => $estado, 'error' => $error, 'progreso' => null, 'job_remoto' => null, 'terminada_en' => now()]);
        if ($estado === 'interrumpida') {
            AvisosService::enviar("⚠ Actualización interrumpida en {$this->sitio->nombre}",
                "{$error}\n\n" . route('webminder-panel.ejecuciones.show', $this));
        }
        if ($this->ronda) {
            app(RondaNocturna::class)->continuar($this->ronda);
        }
    }

    /** Guarda el resultado de un ítem (se persiste al momento: la página de progreso lo lee). */
    public function marcarItem(int $i, string $estado, ?string $mensaje = null, array $extra = []): void
    {
        $items = $this->items;
        $items[$i] = array_merge($items[$i], $extra, ['estado' => $estado, 'mensaje' => $mensaje]);
        $this->update(['items' => $items]);
    }
}
