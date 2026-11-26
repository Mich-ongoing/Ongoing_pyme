<?php

namespace App\Facturacion;

use Illuminate\Database\Eloquent\Model;

class TextoFactura extends Model
{
    protected $table = 'facturacion_textos';

    protected $fillable = ['sociedad', 'anio', 'texto'];
}
