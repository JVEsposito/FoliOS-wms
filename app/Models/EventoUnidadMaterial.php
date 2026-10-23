<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['unidad_material_id', 'item_material_id', 'user_id', 'accion', 'antes', 'despues', 'motivo'])]
class EventoUnidadMaterial extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'eventos_unidad_materiales';

    protected function casts(): array
    {
        return ['antes' => 'array', 'despues' => 'array'];
    }
}
