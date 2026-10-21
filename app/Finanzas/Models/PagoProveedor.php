<?php

namespace App\Finanzas\Models;

use Illuminate\Database\Eloquent\Model;

class PagoProveedor extends Model
{
    protected $connection = 'finanzas';

    protected $table = 'fin_pagos_proveedor';

    protected $guarded = [];

    protected $hidden = ['payload_hash', 'referencia_activa'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['proveedor_snapshot' => 'array', 'fecha' => 'date:Y-m-d', 'monto' => 'decimal:0', 'fecha_anulacion' => 'date:Y-m-d', 'anulado_at' => 'datetime'];
    }
}
