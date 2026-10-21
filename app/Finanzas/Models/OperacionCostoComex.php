<?php

namespace App\Finanzas\Models;

use Illuminate\Database\Eloquent\Model;

class OperacionCostoComex extends Model
{
    protected $connection = 'finanzas';

    protected $table = 'fin_operaciones_comex';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['resultado' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
