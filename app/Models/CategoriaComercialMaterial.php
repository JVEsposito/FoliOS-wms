<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['nombre', 'nombre_normalizado', 'activo', 'creado_por_user_id', 'actualizado_por_user_id'])]
class CategoriaComercialMaterial extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'categorias_comerciales_materiales';

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }
}
