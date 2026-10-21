<?php

namespace App\Rrhh\Models;

use Illuminate\Database\Eloquent\Model;

class IndiceNomina extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $connection = 'rrhh';

    protected $table = 'rh_indices_nomina';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['version' => 'integer', 'uf' => 'decimal:6', 'utm' => 'decimal:6', 'comisiones' => 'array'];
    }
}
