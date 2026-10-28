<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'validacion_mp_id', 'secuencia', 'motivos', 'csg_validacion_id', 'csg_snapshot',
    'cuartel', 'variedad_validacion_id', 'variedad_snapshot', 'bins_prestados_planta', 'estado', 'observacion',
])]
class SegmentoValidacionMp extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'segmentos_validacion_mp';

    public function validacion(): BelongsTo
    {
        return $this->belongsTo(ValidacionMp::class, 'validacion_mp_id');
    }

    public function csg(): BelongsTo
    {
        return $this->belongsTo(CsgValidacion::class, 'csg_validacion_id');
    }

    public function variedad(): BelongsTo
    {
        return $this->belongsTo(VariedadValidacion::class, 'variedad_validacion_id');
    }

    public function envases(): HasMany
    {
        return $this->hasMany(SegmentoEnvaseValidacionMp::class);
    }

    public function lotesMateriaPrima(): HasMany
    {
        return $this->hasMany(LoteMateriaPrima::class, 'segmento_validacion_mp_id');
    }

    protected function casts(): array
    {
        return ['secuencia' => 'integer', 'motivos' => 'array', 'bins_prestados_planta' => 'integer'];
    }
}
