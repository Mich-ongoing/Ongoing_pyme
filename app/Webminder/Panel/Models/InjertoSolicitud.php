<?php

namespace App\Webminder\Panel\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InjertoSolicitud extends Model
{
    protected $table = 'panel_injerto_solicitudes';

    protected $guarded = ['id'];

    protected $casts = [
        'valores' => 'array',
        'anteriores' => 'array',
        'decidida_en' => 'datetime',
    ];

    public function sitio(): BelongsTo
    {
        return $this->belongsTo(SitioWeb::class, 'sitio_id');
    }

    public function decisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decidida_por');
    }
}
