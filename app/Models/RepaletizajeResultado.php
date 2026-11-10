<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'repaletizaje_id',
    'kilos_netos_por_caja', 'folio_id', 'orden', 'tipo_resultado', 'cantidad_objetivo',
    'cantidad_resultante', 'hereda_ubicacion', 'snapshot',
])]
class RepaletizajeResultado extends Model
{
    use HasUuids;

    public function repaletizaje(): BelongsTo
    {
        return $this->belongsTo(Repaletizaje::class);
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    protected function casts(): array
    {
        return [
            'orden' => 'integer',
            'cantidad_objetivo' => 'integer',
            'cantidad_resultante' => 'integer',
            'hereda_ubicacion' => 'boolean',
            'snapshot' => 'array',
        ];
    }
}
