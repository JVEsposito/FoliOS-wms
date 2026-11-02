<?php

namespace App\Models;

use App\Enums\EstadoCargaFolio;
use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'carga_id',
    'folio_id',
    'estado',
    'anden_id',
    'reemplaza_a_carga_folio_id',
    'asignado_por_user_id',
    'asignado_at',
    'enviado_anden_por_user_id',
    'enviado_anden_desde_dispositivo_id',
    'enviado_anden_at',
    'cargado_por_user_id',
    'cargado_desde_dispositivo_id',
    'cargado_at',
    'finalizado_por_user_id',
    'finalizado_at',
    'motivo_finalizacion',
    'retornado_por_user_id',
    'retornado_at',
])]
class CargaFolio extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'carga_folios';

    public function carga(): BelongsTo
    {
        return $this->belongsTo(Carga::class);
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    public function asignadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asignado_por_user_id');
    }

    public function anden(): BelongsTo
    {
        return $this->belongsTo(Anden::class);
    }

    public function reemplazaA(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reemplaza_a_carga_folio_id');
    }

    public function reemplazo(): HasOne
    {
        return $this->hasOne(self::class, 'reemplaza_a_carga_folio_id');
    }

    public function reservaActiva(): HasOne
    {
        return $this->hasOne(ReservaCargaFolio::class, 'carga_folio_id');
    }

    public function incidencias(): HasMany
    {
        return $this->hasMany(IncidenciaCargaFolio::class, 'carga_folio_id');
    }

    public function enviadoAndenPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviado_anden_por_user_id');
    }

    public function enviadoAndenDesdeDispositivo(): BelongsTo
    {
        return $this->belongsTo(Dispositivo::class, 'enviado_anden_desde_dispositivo_id');
    }

    public function cargadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cargado_por_user_id');
    }

    public function finalizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalizado_por_user_id');
    }

    protected function casts(): array
    {
        return [
            'estado' => EstadoCargaFolio::class,
            'asignado_at' => 'datetime',
            'enviado_anden_at' => 'datetime',
            'cargado_at' => 'datetime',
            'retornado_at' => 'datetime',
            'finalizado_at' => 'datetime',
        ];
    }

    /** La temporada es la de la carga. */
    public function temporadaOperacionalId(): ?string
    {
        return $this->carga?->temporadaOperacionalId();
    }
}
