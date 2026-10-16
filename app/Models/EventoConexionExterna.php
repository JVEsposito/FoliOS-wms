<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['conexion_externa_id', 'user_id', 'accion', 'campos_modificados'])]
class EventoConexionExterna extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'eventos_conexion_externa';

    protected function casts(): array
    {
        return ['campos_modificados' => 'array'];
    }
}
