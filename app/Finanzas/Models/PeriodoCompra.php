<?php

namespace App\Finanzas\Models;

use Illuminate\Database\Eloquent\Model;

class PeriodoCompra extends Model
{
    protected $connection = 'finanzas';

    protected $table = 'fin_periodos_compra';

    protected $guarded = [];

    protected $hidden = ['payload_hash'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'mes';

    protected function casts(): array
    {
        return ['cerrado_at' => 'datetime'];
    }
}
