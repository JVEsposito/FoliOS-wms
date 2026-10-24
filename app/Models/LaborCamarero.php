<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['turno_id', 'user_id', 'dispositivo_id', 'clave_labor', 'bloqueo_labor', 'bloqueo_camarero', 'funcion', 'titulo', 'estado', 'motivo', 'contexto', 'vence_at', 'version'])]
class LaborCamarero extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $attributes = ['version' => 1];

    protected $table = 'labores_camarero';

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(Dispositivo::class);
    }

    public function turno(): BelongsTo
    {
        return $this->belongsTo(TurnoPlanificador::class, 'turno_id');
    }

    protected function casts(): array
    {
        return ['contexto' => 'array', 'vence_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
