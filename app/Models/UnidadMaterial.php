<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['codigo', 'nombre', 'activa', 'version', 'actualizado_por_user_id'])]
class UnidadMaterial extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'unidades_materiales';

    protected function casts(): array
    {
        return ['activa' => 'boolean', 'version' => 'integer'];
    }
}
