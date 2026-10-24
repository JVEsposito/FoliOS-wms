<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Concerns\TemporadaPorColumna;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['temporada_id', 'nombre', 'inicio_at', 'fin_at', 'version', 'creado_por_user_id', 'actualizado_por_user_id'])]
class TurnoPlanificador extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica, TemporadaPorColumna;

    protected $attributes = ['version' => 1];

    protected $table = 'turnos_planificador';

    public function asignaciones(): HasMany
    {
        return $this->hasMany(FuncionCamareroTurno::class, 'turno_id');
    }

    public function labores(): HasMany
    {
        return $this->hasMany(LaborCamarero::class, 'turno_id');
    }

    protected function casts(): array
    {
        return ['inicio_at' => 'immutable_datetime', 'fin_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
