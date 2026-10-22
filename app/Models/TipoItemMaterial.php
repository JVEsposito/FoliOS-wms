<?php

namespace App\Models;

use App\Enums\CategoriaOperacionalMaterial;
use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['nombre', 'nombre_normalizado', 'categoria_operacional', 'codigo_base', 'activo', 'creado_por_user_id', 'actualizado_por_user_id'])]
class TipoItemMaterial extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'tipos_items_materiales';

    public function items(): HasMany
    {
        return $this->hasMany(ItemMaterial::class, 'tipo_item_material_id');
    }

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'categoria_operacional' => CategoriaOperacionalMaterial::class];
    }
}
