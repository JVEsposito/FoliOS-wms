<?php

namespace App\Models;

use App\Enums\OrigenVersionOrdenProcesoMp;
use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Versión publicada de una orden de proceso. Inmutable: un cambio crea otra versión. */
#[Fillable([
    'orden_proceso_mp_id',
    'numero',
    'operacion_id',
    'payload_hash',
    'origen',
    'hora_inicio',
    'colacion_desde',
    'colacion_hasta',
    'rendimiento_kg_hora',
    'motivo_cambio',
    'creada_por_user_id',
    'creada_at',
])]
class VersionOrdenProcesoMp extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'versiones_orden_proceso_mp';

    protected function casts(): array
    {
        return [
            'numero' => 'integer',
            'origen' => OrigenVersionOrdenProcesoMp::class,
            'rendimiento_kg_hora' => 'decimal:2',
            'creada_at' => 'datetime',
        ];
    }

    public function temporadaOperacionalId(): ?string
    {
        return $this->orden?->temporada_id;
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenProcesoMp::class, 'orden_proceso_mp_id');
    }

    public function lotes(): HasMany
    {
        return $this->hasMany(LoteVersionOrdenMp::class, 'version_orden_proceso_mp_id')->orderBy('secuencia');
    }

    public function creadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creada_por_user_id');
    }
}
