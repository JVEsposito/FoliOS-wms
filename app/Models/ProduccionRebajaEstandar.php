<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una línea de composición de un pallet validado que entró en una rebaja. */
#[Fillable([
    'rebaja_estandar_material_id', 'validacion_pallet_id', 'numero_folio', 'linea', 'fecha_proceso', 'cliente_id', 'marca',
    'proceso', 'envase_validacion_id', 'variedad', 'calibre', 'categoria', 'cajas', 'estandar_material_id', 'version_estandar_material_id',
])]
class ProduccionRebajaEstandar extends Model
{
    use HasUuids;

    protected $table = 'producciones_rebaja_estandar';

    public function rebaja(): BelongsTo
    {
        return $this->belongsTo(RebajaEstandarMaterial::class, 'rebaja_estandar_material_id');
    }

    protected function casts(): array
    {
        return ['fecha_proceso' => 'date:Y-m-d', 'cajas' => 'integer', 'linea' => 'integer'];
    }
}
