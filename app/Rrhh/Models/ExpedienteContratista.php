<?php

namespace App\Rrhh\Models;

use Illuminate\Database\Eloquent\Model;

class ExpedienteContratista extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $connection = 'rrhh';

    protected $table = 'rh_expedientes_contratistas';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['empleador' => 'array', 'personal' => 'array', 'version' => 'integer', 'personas_count' => 'integer', 'revisado_at' => 'immutable_datetime'];
    }
}
