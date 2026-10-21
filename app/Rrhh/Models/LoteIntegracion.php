<?php

namespace App\Rrhh\Models;

use Illuminate\Database\Eloquent\Model;

class LoteIntegracion extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $connection = 'rrhh';

    protected $table = 'rh_lotes_integracion';

    protected $guarded = [];

    protected $hidden = ['contenido'];

    protected function casts(): array
    {
        return ['consulta' => 'array', 'contenido' => 'encrypted:array', 'resultado' => 'array', 'intentos' => 'integer'];
    }
}
