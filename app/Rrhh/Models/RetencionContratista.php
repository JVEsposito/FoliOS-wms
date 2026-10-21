<?php

namespace App\Rrhh\Models;

use Illuminate\Database\Eloquent\Model;

class RetencionContratista extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $connection = 'rrhh';

    protected $table = 'rh_retenciones_contratistas';

    protected $guarded = [];

    protected $hidden = ['payload_hash'];

    protected function casts(): array
    {
        return ['monto' => 'decimal:0', 'destinatario' => 'array', 'anulado_at' => 'immutable_datetime'];
    }
}
