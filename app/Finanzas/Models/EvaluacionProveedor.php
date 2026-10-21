<?php

namespace App\Finanzas\Models;

use Illuminate\Database\Eloquent\Model;

class EvaluacionProveedor extends Model
{
    protected $connection = 'finanzas';

    protected $table = 'fin_evaluaciones_proveedor';

    protected $guarded = [];

    protected $hidden = ['payload_hash', 'referencia_activa'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['desde' => 'date:Y-m-d', 'hasta' => 'date:Y-m-d', 'criterios' => 'array', 'resultado' => 'decimal:2', 'anulado_at' => 'datetime'];
    }
}
