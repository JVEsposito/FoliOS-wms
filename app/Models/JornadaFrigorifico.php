<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['persona_id', 'ingreso_at', 'salida_at', 'jornada_base_minutos', 'colacion_minutos', 'sector', 'observacion', 'anulado_at', 'version'])]
class JornadaFrigorifico extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'frigorifico_jornadas';

    public function persona(): BelongsTo
    {
        return $this->belongsTo(PersonaFrigorifico::class);
    }

    protected function casts(): array
    {
        return ['ingreso_at' => 'immutable_datetime', 'salida_at' => 'immutable_datetime', 'anulado_at' => 'immutable_datetime',
            'version' => 'integer', 'jornada_base_minutos' => 'integer', 'colacion_minutos' => 'integer'];
    }
}
