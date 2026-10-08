<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'despacho_material_id',
    'item_material_id',
    'cantidad_solicitada',
    'cantidad_despachada',
    'unidad_medida',
    'cantidad_sin_reserva_por_vencimiento',
])]
class DetalleDespachoMaterial extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'detalles_despacho_materiales';

    public function despacho(): BelongsTo
    {
        return $this->belongsTo(DespachoMaterial::class, 'despacho_material_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemMaterial::class, 'item_material_id');
    }

    public function reservas(): HasMany
    {
        return $this->hasMany(ReservaMaterial::class, 'detalle_despacho_material_id');
    }

    public function historialReservas(): HasMany
    {
        return $this->hasMany(ReservaMaterial::class, 'detalle_despacho_material_id')
            ->orderBy('orden_fifo');
    }

    public function retiros(): HasMany
    {
        return $this->hasMany(RetiroMaterial::class, 'detalle_despacho_material_id');
    }

    protected function casts(): array
    {
        return [
            'cantidad_sin_reserva_por_vencimiento' => 'decimal:3',
            'cantidad_solicitada' => 'decimal:3',
            'cantidad_despachada' => 'decimal:3',
        ];
    }
}
