<?php

namespace App\Models;

use App\Enums\RedondeoEstandarMaterial;
use App\Enums\VariacionEstandarMaterial;
use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un insumo de una versión. Sin `item_material_id` el código de planta aún no está
 * homologado con un ítem de Materiales y la rebaja no puede descontarlo.
 */
#[Fillable([
    'version_estandar_material_id',
    'orden',
    'item_material_id',
    'codigo_insumo',
    'descripcion_insumo',
    'unidad_medida',
    'cantidad_por_caja',
    'redondeo',
    'variacion_tipo',
    'variacion_valor',
])]
class LineaEstandarMaterial extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'lineas_estandares_materiales';

    public function version(): BelongsTo
    {
        return $this->belongsTo(VersionEstandarMaterial::class, 'version_estandar_material_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemMaterial::class, 'item_material_id');
    }

    protected function casts(): array
    {
        return [
            'orden' => 'integer',
            'cantidad_por_caja' => 'decimal:6',
            'redondeo' => RedondeoEstandarMaterial::class,
            'variacion_tipo' => VariacionEstandarMaterial::class,
        ];
    }
}
