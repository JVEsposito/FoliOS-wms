<?php

namespace App\Models;

use App\Enums\TipoMovimientoInventarioMaterial;
use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'transferencia_cliente_material_id',
    'folio_id',
    'item_material_id',
    'tipo',
    'cantidad',
    'cantidad_anterior',
    'cantidad_resultante',
    'despacho_material_id',
    'retiro_material_id',
    'orden_transformacion_material_id',
    'lote_transformacion_material_id',
    'user_id',
    'dispositivo_id',
    'destino_nombre',
    'destino_centro_costo',
    'motivo',
    'metadatos',
    'ocurrido_at',
])]
class MovimientoInventarioMaterial extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'movimientos_inventario_materiales';

    public function folioMaterial(): BelongsTo
    {
        return $this->belongsTo(FolioMaterial::class, 'folio_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemMaterial::class, 'item_material_id');
    }

    public function despacho(): BelongsTo
    {
        return $this->belongsTo(DespachoMaterial::class, 'despacho_material_id');
    }

    public function retiro(): BelongsTo
    {
        return $this->belongsTo(RetiroMaterial::class, 'retiro_material_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(Dispositivo::class);
    }

    public function ordenTransformacion(): BelongsTo
    {
        return $this->belongsTo(OrdenTransformacionMaterial::class, 'orden_transformacion_material_id');
    }

    public function loteTransformacion(): BelongsTo
    {
        return $this->belongsTo(LoteTransformacionMaterial::class, 'lote_transformacion_material_id');
    }

    protected function casts(): array
    {
        return [
            'tipo' => TipoMovimientoInventarioMaterial::class,
            'cantidad' => 'decimal:3',
            'cantidad_anterior' => 'decimal:3',
            'cantidad_resultante' => 'decimal:3',
            'metadatos' => 'array',
            'ocurrido_at' => 'datetime',
        ];
    }
}
