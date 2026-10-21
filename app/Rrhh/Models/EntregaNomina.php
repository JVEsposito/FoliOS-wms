<?php

namespace App\Rrhh\Models;

use Illuminate\Database\Eloquent\Model;

class EntregaNomina extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $connection = 'rrhh';

    protected $table = 'rh_entregas_nomina';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['contenido' => 'array', 'secuencia' => 'integer', 'acusado_at' => 'immutable_datetime'];
    }
}
