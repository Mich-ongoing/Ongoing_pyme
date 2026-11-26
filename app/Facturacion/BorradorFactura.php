<?php

namespace App\Facturacion;

use Illuminate\Database\Eloquent\Model;

class BorradorFactura extends Model
{
    protected $table = 'facturacion_borradores';

    protected $fillable = ['mes', 'sociedad', 'contacto_id', 'holded_id', 'total', 'lineas', 'texto', 'user_id'];

    protected $casts = ['lineas' => 'array', 'total' => 'float'];
}
