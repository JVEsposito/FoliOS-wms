<?php

namespace App\Rrhh\Models;

use Illuminate\Database\Eloquent\Model;

class Empleador extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $connection = 'rrhh';

    protected $table = 'rh_empleadores';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'version' => 'integer'];
    }
}
