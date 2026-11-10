<?php

namespace App\Webminder\Panel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AligeraActivacion extends Model
{
    protected $table = 'aligera_activaciones';

    protected $guarded = ['id'];

    protected $casts = [
        'visto_en' => 'datetime',
    ];

    public function licencia(): BelongsTo
    {
        return $this->belongsTo(AligeraLicencia::class, 'licencia_id');
    }
}
