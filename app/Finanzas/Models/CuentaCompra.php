<?php

namespace App\Finanzas\Models;

use Illuminate\Database\Eloquent\Model;

class CuentaCompra extends Model
{
    protected $connection = 'finanzas';

    protected $table = 'fin_cuentas_compra';

    protected $guarded = [];

    protected $hidden = ['payload_hash'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'codigo';

    protected function casts(): array
    {
        return ['activa' => 'boolean'];
    }
}
