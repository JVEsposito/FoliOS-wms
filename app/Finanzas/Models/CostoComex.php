<?php

namespace App\Finanzas\Models;

use Illuminate\Database\Eloquent\Model;

class CostoComex extends Model
{
    protected $connection = 'finanzas';

    protected $table = 'fin_costos_comex';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['fecha' => 'date:Y-m-d', 'previsto' => 'decimal:6', 'comprometido' => 'decimal:6', 'conversion' => 'array', 'fuente' => 'array', 'cierre' => 'array', 'captured_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
