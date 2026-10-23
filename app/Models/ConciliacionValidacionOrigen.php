<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Concerns\TemporadaPorColumna;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConciliacionValidacionOrigen extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica, TemporadaPorColumna;

    protected $table = 'conciliaciones_validacion_origen';

    protected $guarded = ['id'];

    public function validacion(): BelongsTo
    {
        return $this->belongsTo(ValidacionPallet::class, 'validacion_pallet_id');
    }

    protected function casts(): array
    {
        return [
            'validacion_inicial' => 'array', 'validacion_actual' => 'array',
            'planta_inicial' => 'array', 'planta_actual' => 'array', 'comparacion' => 'array',
            'tuvo_diferencias' => 'boolean', 'version' => 'integer',
            'lectura_at' => 'immutable_datetime', 'validado_at' => 'immutable_datetime',
            'comprobado_at' => 'immutable_datetime', 'cuadrado_at' => 'immutable_datetime',
        ];
    }
}
