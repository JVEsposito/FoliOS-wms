<?php

namespace App\Rrhh\Models;

use Illuminate\Database\Eloquent\Model;

class CalculoLegal extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $connection = 'rrhh';

    protected $table = 'rh_calculos_legales';

    protected $guarded = [];

    protected $hidden = ['payload_hash'];

    protected function casts(): array
    {
        return ['entrada' => 'array', 'calculo' => 'array'];
    }
}
