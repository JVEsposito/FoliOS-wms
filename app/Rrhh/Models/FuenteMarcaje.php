<?php

namespace App\Rrhh\Models;

use Illuminate\Database\Eloquent\Model;

class FuenteMarcaje extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $connection = 'rrhh';

    protected $table = 'rh_fuentes';

    protected $guarded = [];

    protected $hidden = ['configuracion'];

    protected function casts(): array
    {
        return ['activa' => 'boolean', 'configuracion' => 'encrypted:array'];
    }
}
