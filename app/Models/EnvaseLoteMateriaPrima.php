<?php

namespace App\Models;

use App\Enums\TipoEnvaseRomana;
use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['lote_materia_prima_id', 'tipo_envase', 'cantidad', 'tara_unitaria'])]
class EnvaseLoteMateriaPrima extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'lotes_materia_prima_envases';

    public function lote(): BelongsTo
    {
        return $this->belongsTo(LoteMateriaPrima::class, 'lote_materia_prima_id');
    }

    protected function casts(): array
    {
        return [
            'tipo_envase' => TipoEnvaseRomana::class,
            'cantidad' => 'integer',
            'tara_unitaria' => 'decimal:3',
        ];
    }
}
