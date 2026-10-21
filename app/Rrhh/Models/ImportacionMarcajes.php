<?php

namespace App\Rrhh\Models;

use Illuminate\Database\Eloquent\Model;

class ImportacionMarcajes extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $connection = 'rrhh';

    protected $table = 'rh_importaciones';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['errores' => 'array'];
    }
}
