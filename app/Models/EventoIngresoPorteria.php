<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventoIngresoPorteria extends Model
{
    protected $table = 'eventos_ingreso_porteria';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['datos' => 'array', 'created_at' => 'datetime'];
    }
}
