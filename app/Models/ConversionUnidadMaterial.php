<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['item_material_id', 'unidad_origen_id', 'unidad_base_id', 'factor', 'activa', 'version', 'actualizado_por_user_id'])]
class ConversionUnidadMaterial extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'conversiones_unidad_materiales';

    public function origen(): BelongsTo
    {
        return $this->belongsTo(UnidadMaterial::class, 'unidad_origen_id');
    }

    protected function casts(): array
    {
        return ['factor' => 'decimal:6', 'activa' => 'boolean', 'version' => 'integer'];
    }
}
