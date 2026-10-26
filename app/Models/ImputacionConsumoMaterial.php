<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['movimiento_almacen_id', 'orden_transformacion_material_id', 'cantidad', 'cantidad_merma', 'snapshot'])]
class ImputacionConsumoMaterial extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'imputaciones_consumo_materiales';

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('La imputación de un consumo confirmado es inmutable.');
        });
    }

    public function movimiento(): BelongsTo
    {
        return $this->belongsTo(MovimientoAlmacenMaterial::class, 'movimiento_almacen_id');
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenTransformacionMaterial::class, 'orden_transformacion_material_id');
    }

    protected function casts(): array
    {
        return ['cantidad' => 'decimal:3', 'cantidad_merma' => 'decimal:3', 'snapshot' => 'array'];
    }
}
