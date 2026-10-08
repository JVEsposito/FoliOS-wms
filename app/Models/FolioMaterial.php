<?php

namespace App\Models;

use App\Enums\CategoriaOperacionalMaterial;
use App\Models\Concerns\ImpideEliminacionFisica;
use App\Services\Materiales\ServicioVencimientoMaterial;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable([
    'folio_id',
    'item_material_id',
    'bulto_recepcion_material_id',
    'lote_transformacion_origen_id',
    'proveedor_material_id',
    'categoria_operacional',
    'cantidad_inicial',
    'cantidad_actual',
    'cantidad_reservada',
    'unidad_medida',
    'lote',
    'fecha_fabricacion',
    'fecha_vencimiento',
    'proveedor',
    'observacion',
    'motivo_bloqueo',
    'bloqueado_por_vencimiento',
    'motivo_bloqueo_previo_vencimiento',
])]
class FolioMaterial extends Model
{
    use ImpideEliminacionFisica;

    protected $table = 'folios_materiales';

    protected $primaryKey = 'folio_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public function estaVencido(?Carbon $fecha = null): bool
    {
        return $this->fecha_vencimiento !== null
            && $this->fecha_vencimiento->toDateString() < ServicioVencimientoMaterial::hoyChile($fecha);
    }

    public function asegurarVigente(): void
    {
        if ($this->estaVencido()) {
            throw new \DomainException($this->mensajeVencimiento());
        }
    }

    public function mensajeVencimiento(): string
    {
        return sprintf('El folio %s venció el %s y no puede utilizarse.',
            $this->folio?->numero_folio ?? $this->folio_id,
            $this->fecha_vencimiento?->format('d-m-Y'),
        );
    }

    public function informacionVencimiento(): array
    {
        return app(ServicioVencimientoMaterial::class)->informacion($this);
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class, 'folio_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemMaterial::class, 'item_material_id');
    }

    public function bultoRecepcion(): BelongsTo
    {
        return $this->belongsTo(BultoRecepcionMaterial::class, 'bulto_recepcion_material_id');
    }

    public function loteTransformacionOrigen(): BelongsTo
    {
        return $this->belongsTo(LoteTransformacionMaterial::class, 'lote_transformacion_origen_id');
    }

    public function proveedorMaterial(): BelongsTo
    {
        return $this->belongsTo(ProveedorMaterial::class, 'proveedor_material_id');
    }

    public function reservas(): HasMany
    {
        return $this->hasMany(ReservaMaterial::class, 'folio_id');
    }

    public function reservasTransformacion(): HasMany
    {
        return $this->hasMany(ReservaTransformacionMaterial::class, 'folio_id');
    }

    public function retiros(): HasMany
    {
        return $this->hasMany(RetiroMaterial::class, 'folio_id');
    }

    public function movimientosInventario(): HasMany
    {
        return $this->hasMany(MovimientoInventarioMaterial::class, 'folio_id');
    }

    public function correccionesItem(): HasMany
    {
        return $this->hasMany(CorreccionItemFolioMaterial::class, 'folio_id');
    }

    public function eventosBloqueo(): HasMany
    {
        return $this->hasMany(EventoBloqueoMaterial::class, 'folio_id');
    }

    protected function casts(): array
    {
        return [
            'categoria_operacional' => CategoriaOperacionalMaterial::class,
            'cantidad_inicial' => 'decimal:3',
            'cantidad_actual' => 'decimal:3',
            'cantidad_reservada' => 'decimal:3',
            'bloqueado_por_vencimiento' => 'boolean',
            'fecha_fabricacion' => 'date',
            'fecha_vencimiento' => 'date',
        ];
    }
}
