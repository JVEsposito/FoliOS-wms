<?php

namespace App\Rrhh\Models;

use Illuminate\Database\Eloquent\Model;

class AsignacionPersonal extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $connection = 'rrhh';

    protected $table = 'rh_asignaciones';

    protected $guarded = [];

    protected $hidden = ['payload_hash'];

    protected function casts(): array
    {
        return ['desde_at' => 'immutable_datetime', 'hasta_at' => 'immutable_datetime', 'costo_hora' => 'decimal:6', 'version' => 'integer'];
    }
}
