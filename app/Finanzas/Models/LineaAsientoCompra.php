<?php

namespace App\Finanzas\Models;

use Illuminate\Database\Eloquent\Model;

class LineaAsientoCompra extends Model
{
    protected $connection = 'finanzas';

    protected $table = 'fin_asiento_compra_lineas';

    protected $guarded = [];

    protected $hidden = ['payload_hash'];

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['debe' => 'decimal:0', 'haber' => 'decimal:0'];
    }
}
