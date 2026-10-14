<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cuadratura de un componente al cerrar una orden en modo cuadratura:
 * retirado = utilizado + merma + sin armar; desviación = utilizado − teórico.
 */
#[Fillable([
    'orden_transformacion_material_id',
    'item_material_id',
    'es_componente_principal',
    'cantidad_retirada',
    'cantidad_teorica',
    'cantidad_merma',
    'cantidad_sin_armar',
    'cantidad_utilizada',
    'desviacion',
])]
class CuadraturaTransformacionMaterial extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'cuadraturas_transformacion_materiales';

    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenTransformacionMaterial::class, 'orden_transformacion_material_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemMaterial::class, 'item_material_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'es_componente_principal' => 'boolean',
            'cantidad_retirada' => 'decimal:3',
            'cantidad_teorica' => 'decimal:3',
            'cantidad_merma' => 'decimal:3',
            'cantidad_sin_armar' => 'decimal:3',
            'cantidad_utilizada' => 'decimal:3',
            'desviacion' => 'decimal:3',
        ];
    }
}
