<?php

namespace App\Models;

use App\Enums\TipoMovimientoAlmacenMaterial;
use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'incidencia_verificacion_id',
    'operacion_id',
    'secuencia',
    'payload_hash',
    'tipo',
    'folio_id',
    'item_material_id',
    'almacen_origen_id',
    'almacen_destino_id',
    'cantidad',
    'saldo_origen_anterior',
    'saldo_origen_resultante',
    'saldo_destino_anterior',
    'saldo_destino_resultante',
    'centro_costo',
    'motivo',
    'documento_relacionado',
    'despacho_material_id',
    'retiro_material_id',
    'user_id',
    'dispositivo_id',
    'metadatos',
    'ocurrido_at',
])]
class MovimientoAlmacenMaterial extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'movimientos_almacenes_materiales';

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException(
                'Los movimientos de almacén son inmutables. Registre un movimiento inverso.',
            );
        });
    }

    public function folioMaterial(): BelongsTo
    {
        return $this->belongsTo(FolioMaterial::class, 'folio_id', 'folio_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemMaterial::class, 'item_material_id');
    }

    public function almacenOrigen(): BelongsTo
    {
        return $this->belongsTo(AlmacenMaterial::class, 'almacen_origen_id');
    }

    public function almacenDestino(): BelongsTo
    {
        return $this->belongsTo(AlmacenMaterial::class, 'almacen_destino_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(Dispositivo::class);
    }

    protected function casts(): array
    {
        return [
            'tipo' => TipoMovimientoAlmacenMaterial::class,
            'cantidad' => 'decimal:3',
            'saldo_origen_anterior' => 'decimal:3',
            'saldo_origen_resultante' => 'decimal:3',
            'saldo_destino_anterior' => 'decimal:3',
            'saldo_destino_resultante' => 'decimal:3',
            'metadatos' => 'array',
            'ocurrido_at' => 'datetime',
        ];
    }
}
