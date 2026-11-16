<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lo que una rebaja calculó y descontó de un ítem. */
#[Fillable([
    'rebaja_estandar_material_id', 'item_material_id', 'unidad_medida', 'redondeo', 'teorico', 'fraccion_anterior', 'pendiente_anterior',
    'a_descontar', 'descontado', 'pendiente', 'fraccion_resultante', 'folios',
])]
class ConsumoRebajaEstandar extends Model
{
    use HasUuids;

    protected $table = 'consumos_rebaja_estandar';

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemMaterial::class, 'item_material_id');
    }

    protected function casts(): array
    {
        return [
            'teorico' => 'decimal:6', 'fraccion_anterior' => 'decimal:6', 'pendiente_anterior' => 'decimal:6', 'a_descontar' => 'decimal:6',
            'descontado' => 'decimal:6', 'pendiente' => 'decimal:6', 'fraccion_resultante' => 'decimal:6', 'folios' => 'integer',
        ];
    }
}
