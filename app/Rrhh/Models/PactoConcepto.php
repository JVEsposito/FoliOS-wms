<?php

namespace App\Rrhh\Models;

use Illuminate\Database\Eloquent\Model;

class PactoConcepto extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $connection = 'rrhh';

    protected $table = 'rh_pactos_conceptos';

    protected $guarded = [];

    protected $hidden = ['payload_hash'];

    protected function casts(): array
    {
        return ['concepto' => 'array'];
    }
}
