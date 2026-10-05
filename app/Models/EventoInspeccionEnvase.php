<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EventoInspeccionEnvase extends Model
{
    use HasUuids;

    protected $table = 'eventos_inspeccion_envases';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['antes' => 'array', 'despues' => 'array'];
    }
}
