<?php

namespace App\Rrhh\Models;

use Illuminate\Database\Eloquent\Model;

class VinculoMarcaje extends Model
{
    protected $connection = 'rrhh';

    protected $table = 'rh_vinculos';

    protected $guarded = [];

    protected function casts(): array
    {
        return [];
    }
}
