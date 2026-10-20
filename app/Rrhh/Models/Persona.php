<?php

namespace App\Rrhh\Models;

use Illuminate\Database\Eloquent\Model;

class Persona extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $connection = 'rrhh';

    protected $table = 'rh_personas';

    protected $guarded = [];

    protected $hidden = ['pin_hash'];

    protected function casts(): array
    {
        return ['activa' => 'boolean', 'version' => 'integer'];
    }
}
