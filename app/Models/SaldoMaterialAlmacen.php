<?php

namespace App\Models;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoCamara;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoPosicion;
use App\Enums\TipoAlmacenMaterial;
use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'folio_id',
    'almacen_material_id',
    'cantidad_actual',
    'cantidad_reservada',
    'camara_id',
    'posicion_id',
    'version',
])]
class SaldoMaterialAlmacen extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'saldos_materiales_almacenes';

    public function folioMaterial(): BelongsTo
    {
        return $this->belongsTo(FolioMaterial::class, 'folio_id', 'folio_id');
    }

    public function almacen(): BelongsTo
    {
        return $this->belongsTo(AlmacenMaterial::class, 'almacen_material_id');
    }

    public function camara(): BelongsTo
    {
        return $this->belongsTo(Camara::class);
    }

    public function posicion(): BelongsTo
    {
        return $this->belongsTo(Posicion::class);
    }

    public function reservas(): HasMany
    {
        return $this->hasMany(ReservaMaterial::class, 'saldo_material_almacen_id');
    }

    public function cantidadDisponible(): float
    {
        return max(
            0,
            round(
                (float) $this->cantidad_actual - (float) $this->cantidad_reservada,
                3,
            ),
        );
    }

    /** La aptitud física corresponde al saldo, aunque otro saldo del folio esté sin ubicar. */
    public function esReservable(): bool
    {
        return $this->almacen?->tipo === TipoAlmacenMaterial::Fisica
            && $this->almacen->activo
            && $this->folioMaterial?->folio?->activo
            && in_array($this->folioMaterial->folio->estado_operacional, [EstadoOperacionalFolio::Disponible, EstadoOperacionalFolio::PendienteUbicacion], true)
            && ! $this->folioMaterial->estaVencido()
            && $this->folioMaterial->motivo_bloqueo === null
            && $this->camara?->contenido === ContenidoCamara::Materiales
            && $this->camara->estado === EstadoCamara::Activa
            && (! $this->posicion_id || $this->posicion?->estado === EstadoPosicion::Activa);
    }

    protected function casts(): array
    {
        return [
            'cantidad_actual' => 'decimal:3',
            'cantidad_reservada' => 'decimal:3',
            'version' => 'integer',
        ];
    }
}
