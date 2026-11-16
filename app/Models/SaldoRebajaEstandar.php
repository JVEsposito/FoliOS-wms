<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Fracción arrastrada y faltante por falta de saldo de un ítem en un almacén. */
#[Fillable(['almacen_material_id', 'item_material_id', 'fraccion', 'pendiente'])]
class SaldoRebajaEstandar extends Model
{
    use HasUuids;

    protected $table = 'saldos_rebaja_estandar';

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemMaterial::class, 'item_material_id');
    }

    protected function casts(): array
    {
        return ['fraccion' => 'decimal:6', 'pendiente' => 'decimal:6'];
    }
}
