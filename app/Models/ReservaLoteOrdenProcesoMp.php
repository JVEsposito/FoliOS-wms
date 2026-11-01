<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Compromiso vigente de un lote con una orden. Solo se escribe con el lote bloqueado,
 * así la suma de pendientes por lote no puede superar su saldo en cámara.
 */
#[Fillable([
    'orden_proceso_mp_id',
    'lote_materia_prima_id',
    'bins_programados',
    'bins_entregados',
    'bins_pendientes',
])]
class ReservaLoteOrdenProcesoMp extends Model
{
    use HasUuids;

    protected $table = 'reservas_lote_orden_proceso_mp';

    protected function casts(): array
    {
        return [
            'bins_programados' => 'integer',
            'bins_entregados' => 'integer',
            'bins_pendientes' => 'integer',
        ];
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenProcesoMp::class, 'orden_proceso_mp_id');
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(LoteMateriaPrima::class, 'lote_materia_prima_id');
    }
}
