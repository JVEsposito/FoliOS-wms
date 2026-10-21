<?php

namespace App\Rrhh\Models;

use Illuminate\Database\Eloquent\Model;

class AntecedenteContratista extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $connection = 'rrhh';

    protected $table = 'rh_antecedentes_contratistas';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['personas' => 'array', 'version' => 'integer', 'revisado_at' => 'immutable_datetime'];
    }
}
