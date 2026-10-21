<?php

namespace App\Rrhh\Models;

use Illuminate\Database\Eloquent\Model;

class ConceptoNomina extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $connection = 'rrhh';

    protected $table = 'rh_conceptos_nomina';

    protected $guarded = [];

    protected $hidden = ['payload_hash'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'version' => 'integer', 'remuneracion' => 'boolean', 'imponible' => 'boolean', 'tributable' => 'boolean', 'base_he' => 'boolean'];
    }
}
