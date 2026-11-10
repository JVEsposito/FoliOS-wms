<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'repaletizaje_id',
    'kilos_aportados',
    'folio_origen_id',
    'orden',
    'es_folio_conservado',
    'cajas_antes',
    'cajas_aportadas',
    'cajas_despues',
    'tipo_bulto_antes',
    'tipo_bulto_despues',
    'estado_antes',
    'estado_despues',
    'snapshot_antes',
    'snapshot_despues',
])]
class RepaletizajeDetalle extends Model
{
    use HasUuids;

    public function repaletizaje(): BelongsTo
    {
        return $this->belongsTo(Repaletizaje::class);
    }

    public function folioOrigen(): BelongsTo
    {
        return $this->belongsTo(Folio::class, 'folio_origen_id');
    }

    protected function casts(): array
    {
        return [
            'orden' => 'integer',
            'es_folio_conservado' => 'boolean',
            'cajas_antes' => 'integer',
            'cajas_aportadas' => 'integer',
            'cajas_despues' => 'integer',
            'snapshot_antes' => 'array',
            'snapshot_despues' => 'array',
        ];
    }
}
