<?php

namespace App\Rrhh\Models;

use Illuminate\Database\Eloquent\Model;

class Afiliacion extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $connection = 'rrhh';

    protected $table = 'rh_afiliaciones';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['institucion' => 'array', 'precio_plan' => 'decimal:6'];
    }
}
