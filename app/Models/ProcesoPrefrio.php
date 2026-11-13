<?php

namespace App\Models;

use App\Enums\EstadoProcesoPrefrio;
use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Concerns\TemporadaPorColumna;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'temporada_id',
    'codigo',
    'operacion_id',
    'payload_hash',
    'tunel_prefrio_id',
    'estado',
    'setpoint',
    'duracion_objetivo_minutos',
    'formato_referencia',
    'version',
    'creado_por_user_id',
    'dispositivo_id',
    'iniciado_por_user_id',
    'finalizado_por_user_id',
    'iniciado_at',
    'pendiente_verificacion_at',
    'finalizado_at',
    'observacion',
    'registro_r41',
])]
class ProcesoPrefrio extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica, TemporadaPorColumna;

    protected $table = 'procesos_prefrio';

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function tunel(): BelongsTo
    {
        return $this->belongsTo(TunelPrefrio::class, 'tunel_prefrio_id');
    }

    public function folios(): HasMany
    {
        return $this->hasMany(ProcesoPrefrioFolio::class);
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(EventoPrefrio::class);
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por_user_id');
    }

    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(Dispositivo::class);
    }

    public function iniciadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'iniciado_por_user_id');
    }

    public function finalizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalizado_por_user_id');
    }

    protected function casts(): array
    {
        return [
            'estado' => EstadoProcesoPrefrio::class,
            'setpoint' => 'decimal:2',
            'duracion_objetivo_minutos' => 'integer',
            'version' => 'integer',
            'iniciado_at' => 'datetime',
            'pendiente_verificacion_at' => 'datetime',
            'finalizado_at' => 'datetime',
            // Datos del POEMP R-41 que no salen de la operación: T° objetivo, supervisor, servicio.
            'registro_r41' => 'array',
        ];
    }
}
