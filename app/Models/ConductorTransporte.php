<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['rut', 'nombre', 'telefono', 'version', 'creado_por_user_id', 'actualizado_por_user_id'])]
class ConductorTransporte extends Model
{
    use HasUuids;

    protected $table = 'conductores_transporte';

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
