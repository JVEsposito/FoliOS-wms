<?php

namespace App\Models;

use App\Enums\TipoEnvaseRomana;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['recepcion_romana_id', 'tipo_envase', 'cantidad', 'tara_unitaria', 'movimiento_envase_id'])]
class SalidaEnvaseRecepcionRomana extends Model
{
    use HasUuids;

    protected $table = 'salidas_envases_recepcion_romana';

    public function recepcion(): BelongsTo
    {
        return $this->belongsTo(RecepcionRomana::class, 'recepcion_romana_id');
    }

    protected function casts(): array
    {
        return ['tipo_envase' => TipoEnvaseRomana::class, 'cantidad' => 'integer', 'tara_unitaria' => 'decimal:3'];
    }
}
