<?php

namespace App\Rrhh\Models;

use Illuminate\Database\Eloquent\Model;

class MovimientoNomina extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $connection = 'rrhh';

    protected $table = 'rh_movimientos_nomina';

    protected $guarded = [];

    protected $hidden = ['payload_hash'];

    protected function casts(): array
    {
        return ['entrada' => 'array', 'calculo' => 'array', 'aprobado_at' => 'immutable_datetime', 'anulado_at' => 'immutable_datetime'];
    }
}
