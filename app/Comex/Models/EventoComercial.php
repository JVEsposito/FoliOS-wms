<?php

namespace App\Comex\Models;

use Illuminate\Database\Eloquent\Model;

class EventoComercial extends Model
{
    protected $connection = 'comex';

    protected $table = 'cx_eventos_comerciales';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['datos' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
