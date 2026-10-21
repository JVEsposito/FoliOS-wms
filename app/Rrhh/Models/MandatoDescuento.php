<?php

namespace App\Rrhh\Models;

use Illuminate\Database\Eloquent\Model;

class MandatoDescuento extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $connection = 'rrhh';

    protected $table = 'rh_mandatos_descuento';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['concepto' => 'array', 'institucion' => 'array', 'cuotas' => 'integer', 'pago_directo_institucion' => 'boolean'];
    }
}
