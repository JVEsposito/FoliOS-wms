<?php

namespace App\Models;

use App\Enums\EstadoOrdenProcesoMp;
use App\Enums\TipoCierreOrdenProcesoMp;
use App\Enums\TurnoProceso;
use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Concerns\TemporadaPorColumna;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'operacion_id',
    'payload_hash',
    'temporada_id',
    'correlativo',
    'codigo',
    'linea_proceso_id',
    'fecha',
    'turno_inicio',
    'estado',
    'tipo_cierre',
    'version_vigente_id',
    'numero_version_vigente',
    'version',
    'creado_por_user_id',
    'cerrado_por_user_id',
    'cerrado_at',
    'motivo_cancelacion',
])]
class OrdenProcesoMp extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica, TemporadaPorColumna;

    protected $table = 'ordenes_proceso_mp';

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'turno_inicio' => TurnoProceso::class,
            'estado' => EstadoOrdenProcesoMp::class,
            'tipo_cierre' => TipoCierreOrdenProcesoMp::class,
            'correlativo' => 'integer',
            'numero_version_vigente' => 'integer',
            'version' => 'integer',
            'cerrado_at' => 'datetime',
        ];
    }

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function linea(): BelongsTo
    {
        return $this->belongsTo(LineaProceso::class, 'linea_proceso_id');
    }

    public function versionVigente(): BelongsTo
    {
        return $this->belongsTo(VersionOrdenProcesoMp::class, 'version_vigente_id');
    }

    public function versiones(): HasMany
    {
        return $this->hasMany(VersionOrdenProcesoMp::class, 'orden_proceso_mp_id')->orderBy('numero');
    }

    public function reservas(): HasMany
    {
        return $this->hasMany(ReservaLoteOrdenProcesoMp::class, 'orden_proceso_mp_id');
    }

    public function entregas(): HasMany
    {
        return $this->hasMany(EntregaFrutaProceso::class, 'orden_proceso_mp_id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por_user_id');
    }

    public function cerradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrado_por_user_id');
    }
}
