<?php

namespace App\Models;

use App\Enums\TipoEnvaseRomana;
use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `cantidad` son los envases con fruta del segmento, los que pasan al lote. `cantidad_vacia`
 * son los que llegaron vacíos con ese segmento: cuentan para la cuenta corriente, no para el lote.
 */
#[Fillable(['segmento_validacion_mp_id', 'tipo_envase', 'cantidad', 'cantidad_vacia'])]
class SegmentoEnvaseValidacionMp extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'segmentos_envases_validacion_mp';

    public function segmento(): BelongsTo
    {
        return $this->belongsTo(SegmentoValidacionMp::class, 'segmento_validacion_mp_id');
    }

    protected function casts(): array
    {
        return ['tipo_envase' => TipoEnvaseRomana::class, 'cantidad' => 'integer', 'cantidad_vacia' => 'integer'];
    }
}
