<?php

namespace App\Webminder\Panel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventoActividad extends Model
{
    protected $table = 'panel_eventos_actividad';

    protected $guarded = ['id'];

    protected $casts = [
        'ocurrido_en' => 'datetime',
        'detalles' => 'array',
    ];

    public function sitio(): BelongsTo
    {
        return $this->belongsTo(SitioWeb::class, 'sitio_id');
    }
}
