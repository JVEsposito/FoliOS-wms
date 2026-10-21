<?php

namespace App\Comex\Models;

use Illuminate\Database\Eloquent\Model;

class NotaVenta extends Model
{
    protected $connection = 'comex';

    protected $table = 'cx_notas_venta';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['cliente' => 'array', 'version' => 'integer'];
    }
}
