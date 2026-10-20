<?php

namespace App\Rrhh\Models;

use Illuminate\Database\Eloquent\Model;

class EventoRrhh extends Model
{
    public $timestamps = false;

    protected $connection = 'rrhh';

    protected $table = 'rh_eventos';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['datos' => 'array', 'created_at' => 'datetime'];
    }
}
