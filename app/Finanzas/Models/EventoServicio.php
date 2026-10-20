<?php

namespace App\Finanzas\Models;

use Illuminate\Database\Eloquent\Model;

class EventoServicio extends Model
{
    public $timestamps = false;

    protected $connection = 'finanzas';

    protected $table = 'fin_servicios_eventos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['datos' => 'array', 'created_at' => 'datetime'];
    }
}
