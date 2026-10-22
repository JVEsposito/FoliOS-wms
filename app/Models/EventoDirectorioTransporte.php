<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tipo', 'registro_id', 'operacion_id', 'antes', 'despues', 'user_id', 'usuario'])]
class EventoDirectorioTransporte extends Model
{
    use HasUuids;

    protected $table = 'eventos_directorio_transporte';

    protected function casts(): array
    {
        return ['antes' => 'array', 'despues' => 'array'];
    }
}
