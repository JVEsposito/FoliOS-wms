<?php

namespace App\Rrhh\Models;

use Illuminate\Database\Eloquent\Model;

class Institucion extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $connection = 'rrhh';

    protected $table = 'rh_instituciones';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'version' => 'integer'];
    }
}
