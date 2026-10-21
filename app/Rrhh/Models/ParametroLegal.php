<?php

namespace App\Rrhh\Models;

use Illuminate\Database\Eloquent\Model;

class ParametroLegal extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $connection = 'rrhh';

    protected $table = 'rh_parametros_legales';

    protected $guarded = [];

    protected $hidden = ['payload_hash'];

    protected function casts(): array
    {
        return [];
    }
}
