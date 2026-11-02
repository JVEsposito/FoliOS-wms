<?php

namespace App\Models;

use App\Enums\EstadoCarga;
use App\Enums\ModalidadSalidaCarga;
use App\Enums\PrioridadCarga;
use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Concerns\TemporadaPorColumna;
use App\Models\Contracts\PerteneceATemporada;
use App\Observers\ReplanificarConcentracionCargaObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[ObservedBy([ReplanificarConcentracionCargaObserver::class])]
#[Fillable([
    'temporada_id',
    'codigo',
    'numero_orden_externa',
    'estado',
    'modalidad_salida',
    'prioridad',
    'camara_objetivo_id',
    'anden_previsto_id',
    'observacion',
    'version',
    'creada_por_user_id',
    'actualizada_por_user_id',
    'publicada_por_user_id',
    'publicada_at',
    'cancelada_por_user_id',
    'cancelada_at',
    'motivo_cancelacion',
    'operacion_cierre_id',
    'cierre_payload_hash',
    'patente',
    'conductor',
    'observacion_cierre',
    'cerrada_por_user_id',
    'cerrada_at',
    'cierre_registrado_at',
])]
class Carga extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica, TemporadaPorColumna;

    /** @var array<string, mixed> */
    protected $attributes = [
        'modalidad_salida' => ModalidadSalidaCarga::DesdeCamara->value,
    ];

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function camaraObjetivo(): BelongsTo
    {
        return $this->belongsTo(Camara::class, 'camara_objetivo_id');
    }

    public function creadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creada_por_user_id');
    }

    public function andenPrevisto(): BelongsTo
    {
        return $this->belongsTo(Anden::class, 'anden_previsto_id');
    }

    public function actualizadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizada_por_user_id');
    }

    public function publicadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'publicada_por_user_id');
    }

    public function canceladaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelada_por_user_id');
    }

    public function cerradaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrada_por_user_id');
    }

    public function asignacionesActuales(): HasMany
    {
        return $this->hasMany(CargaFolio::class)
            ->whereHas('reservaActiva')
            ->orderBy('asignado_at');
    }

    public function asignacionesHistoricas(): HasMany
    {
        return $this->hasMany(CargaFolio::class)
            ->orderBy('asignado_at');
    }

    public function tareas(): HasMany
    {
        return $this->hasMany(TareaCarga::class);
    }

    public function presenciasAnden(): HasMany
    {
        return $this->hasMany(PresenciaCargaAnden::class)
            ->orderBy('ingresada_at');
    }

    public function presenciaAndenActiva(): HasOne
    {
        return $this->hasOne(PresenciaCargaAnden::class, 'bloqueo_carga_id');
    }

    public function incidencias(): HasManyThrough
    {
        return $this->hasManyThrough(
            IncidenciaCargaFolio::class,
            CargaFolio::class,
            'carga_id',
            'carga_folio_id',
        );
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(EventoCarga::class)
            ->orderBy('created_at');
    }

    public function embarque(): HasOne
    {
        return $this->hasOne(Embarque::class);
    }

    protected function casts(): array
    {
        return [
            'estado' => EstadoCarga::class,
            'modalidad_salida' => ModalidadSalidaCarga::class,
            'prioridad' => PrioridadCarga::class,
            'version' => 'integer',
            'publicada_at' => 'datetime',
            'cancelada_at' => 'datetime',
            'cerrada_at' => 'datetime',
            'cierre_registrado_at' => 'datetime',
        ];
    }
}
