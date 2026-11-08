<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['persona_id', 'desde', 'hasta', 'tipo', 'observacion', 'anulado_at', 'version'])]
class AusenciaFrigorifico extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    public const TIPOS = [
        'falta' => 'Falta',
        'permiso' => 'Permiso',
        'licencia' => 'Licencia',
        'libre' => 'Libre',
        'vacaciones' => 'Vacaciones',
    ];

    protected $table = 'frigorifico_ausencias';

    public function persona(): BelongsTo
    {
        return $this->belongsTo(PersonaFrigorifico::class);
    }

    protected function casts(): array
    {
        return ['desde' => 'date:Y-m-d', 'hasta' => 'date:Y-m-d', 'anulado_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
