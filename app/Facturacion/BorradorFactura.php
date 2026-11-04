<?php

namespace App\Facturacion;

use Illuminate\Database\Eloquent\Model;

class BorradorFactura extends Model
{
    protected $table = 'facturacion_borradores';

    protected $fillable = ['mes', 'sociedad', 'holded_id', 'total', 'lineas', 'user_id'];

    protected $casts = ['lineas' => 'array', 'total' => 'float'];
}
