<?php

namespace App\Rrhh\Models;

use Illuminate\Database\Eloquent\Model;

class ContratoLaboral extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $connection = 'rrhh';

    protected $table = 'rh_contratos';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['empleador' => 'array'];
    }
}
