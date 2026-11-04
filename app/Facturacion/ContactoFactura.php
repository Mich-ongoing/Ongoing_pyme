<?php

namespace App\Facturacion;

use Illuminate\Database\Eloquent\Model;

class ContactoFactura extends Model
{
    protected $table = 'facturacion_contactos';

    protected $fillable = ['sociedad', 'holded_id'];
}
