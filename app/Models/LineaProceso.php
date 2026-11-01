<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'codigo',
    'nombre',
    'rendimiento_kg_hora',
    'activa',
    'orden',
    'version',
    'actualizado_por_user_id',
])]
class LineaProceso extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'lineas_proceso';

    protected function casts(): array
    {
        return [
            'rendimiento_kg_hora' => 'decimal:2',
            'activa' => 'boolean',
            'orden' => 'integer',
            'version' => 'integer',
        ];
    }

    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por_user_id');
    }

    /** @param Builder<self> $consulta */
    public function scopeOrdenadas(Builder $consulta): Builder
    {
        return $consulta->orderBy('orden')->orderBy('codigo');
    }
}
