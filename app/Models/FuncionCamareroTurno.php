<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['turno_id', 'user_id', 'funcion_principal', 'funciones_apoyo', 'preferencia', 'disponible', 'version'])]
class FuncionCamareroTurno extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica;

    protected $attributes = ['version' => 1];

    protected $table = 'funciones_camarero_turno';

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function turno(): BelongsTo
    {
        return $this->belongsTo(TurnoPlanificador::class, 'turno_id');
    }

    public function temporadaOperacionalId(): ?string
    {
        return $this->turno?->temporadaOperacionalId();
    }

    protected function casts(): array
    {
        return ['funciones_apoyo' => 'array', 'preferencia' => 'array', 'disponible' => 'boolean', 'version' => 'integer'];
    }
}
