<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Eliminación administrativa de un folio que la tablet creó sin Validación PT. */
#[Fillable([
    'operacion_id',
    'payload_hash',
    'folio_id',
    'numero_folio',
    'numero_folio_archivado',
    'motivo',
    'eliminado_por_user_id',
    'eliminado_at',
    'snapshot',
])]
class EliminacionFolioManual extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'eliminaciones_folio_manual';

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    public function eliminadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'eliminado_por_user_id');
    }

    protected function casts(): array
    {
        return [
            'eliminado_at' => 'datetime',
            'snapshot' => 'array',
        ];
    }
}
