<?php

namespace App\Models;

use App\Enums\PropiedadEnvase;
use App\Enums\TipoEnvaseRomana;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'recepcion_romana_id',
    'tipo_envase',
    'propiedad',
    'cantidad_declarada',
    'cantidad_validada',
    'cantidad_vacia',
    'tara_unitaria_salida',
])]
class DetalleEnvaseRecepcionRomana extends Model
{
    use HasUuids;

    protected $table = 'detalles_envases_recepcion_romana';

    public function recepcion(): BelongsTo
    {
        return $this->belongsTo(RecepcionRomana::class, 'recepcion_romana_id');
    }

    protected function casts(): array
    {
        return [
            'tipo_envase' => TipoEnvaseRomana::class,
            'propiedad' => PropiedadEnvase::class,
            'cantidad_declarada' => 'integer',
            'cantidad_validada' => 'integer',
            'cantidad_vacia' => 'integer',
            'tara_unitaria_salida' => 'decimal:3',
        ];
    }

    /**
     * Envases con fruta: lo contado en Validación MP menos los que llegaron vacíos. Es la
     * base del peso promedio por envase; la cuenta corriente y la tara usan todo lo contado.
     */
    public function cantidadConFruta(): int
    {
        return max(0, (int) ($this->cantidad_validada ?? 0) - (int) $this->cantidad_vacia);
    }
}
