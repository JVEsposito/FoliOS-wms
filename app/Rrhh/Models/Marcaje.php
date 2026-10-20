<?php

namespace App\Rrhh\Models;

use Illuminate\Database\Eloquent\Model;

class Marcaje extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $connection = 'rrhh';

    protected $table = 'rh_marcajes';

    protected $guarded = [];

    protected $hidden = ['payload_hash'];

    protected function casts(): array
    {
        return ['instante_at' => 'immutable_datetime', 'anulado_at' => 'datetime'];
    }
}
