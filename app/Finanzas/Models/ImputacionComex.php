<?php

namespace App\Finanzas\Models;

use Illuminate\Database\Eloquent\Model;

class ImputacionComex extends Model
{
    protected $connection = 'finanzas';

    protected $table = 'fin_imputaciones_comex';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['linea' => 'integer', 'monto' => 'decimal:0', 'signo' => 'integer', 'documento' => 'array', 'activa' => 'boolean'];
    }
}
