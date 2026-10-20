<?php

namespace App\Finanzas\Models;

use Illuminate\Database\Eloquent\Model;

class EventoCargo extends Model
{
    public $timestamps = false;

    protected $connection = 'finanzas';

    protected $table = 'fin_cargos_eventos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['datos' => 'array', 'created_at' => 'datetime'];
    }
}
