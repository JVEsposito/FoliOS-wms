<?php

namespace App\Models;

use App\Enums\CondicionTermicaFolio;
use App\Enums\EstadoIntegracionFolio;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoValidacionPallet;
use App\Enums\FuenteHabilitacionAlmacenamiento;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\ResultadoValidacionPallet;
use App\Enums\TipoBulto;
use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Contracts\PerteneceATemporada;
use App\Services\Temporadas\ServicioTemporadaActiva;
use App\Services\Validacion\ProyeccionTrazabilidadFolio;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'temporada_id',
    'numero_folio',
    'tipo_bulto',
    'condicion_sag_id',
    'estado_operacional',
    'condicion_termica',
    'habilitacion_almacenamiento',
    'fuente_habilitacion_almacenamiento',
    'habilitado_almacenamiento_at',
    'habilitado_almacenamiento_por_user_id',
    'retencion_termica_motivo',
    'fecha_ingreso',
    'activo',
    'variedad',
    'calibre',
    'marca',
    'exportadora',
    'origen_sistema',
    'identificador_externo',
    'estado_integracion',
    'sincronizado_at',
    'datos_externos',
    'fecha_proceso_pt',
])]
class Folio extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica;

    protected static function booted(): void
    {
        // La columna calculada de integración nunca se escribe al replicar un folio.
        static::saving(fn (Folio $folio) => $folio->offsetUnset('clave_identificador_integracion'));

        // La composición vive en datos_externos; su proyección consultable se mantiene al día.
        static::saved(function (Folio $folio): void {
            if ($folio->wasRecentlyCreated || $folio->wasChanged(['datos_externos', 'temporada_id'])) {
                app(ProyeccionTrazabilidadFolio::class)->sincronizar($folio);
            }
        });

        static::creating(function (Folio $folio): void {
            if ($folio->temporada_id !== null) {
                return;
            }

            $folio->temporada_id = app(ServicioTemporadaActiva::class)->buscar()?->id
                ?? throw new DomainException(
                    'No existe una temporada global activa. Un administrador debe activarla desde Accesos.',
                );
        });
    }

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function condicionSag(): BelongsTo
    {
        return $this->belongsTo(CondicionSag::class);
    }

    public function ubicacionActual(): HasOne
    {
        return $this->hasOne(UbicacionActual::class);
    }

    public function asignacionCargaActual(): HasOne
    {
        // Gerencia replica este criterio en el EXISTS agregado de ServicioPanelGerencial::productos().
        // Si cambia qué significa reserva activa, actualizar allí también el conteo de comprometidos.
        return $this->hasOne(CargaFolio::class)
            ->whereHas('reservaActiva');
    }

    public function asignacionesCarga(): HasMany
    {
        return $this->hasMany(CargaFolio::class);
    }

    public function reservaCargaActual(): HasOne
    {
        return $this->hasOne(ReservaCargaFolio::class);
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(Movimiento::class);
    }

    public function tareasMovimiento(): HasMany
    {
        return $this->hasMany(TareaMovimiento::class);
    }

    public function material(): HasOne
    {
        return $this->hasOne(FolioMaterial::class, 'folio_id');
    }

    public function procesosPrefrio(): HasMany
    {
        return $this->hasMany(ProcesoPrefrioFolio::class);
    }

    public function validacionPallet(): HasOne
    {
        return $this->hasOne(ValidacionPallet::class)
            ->where('estado', EstadoValidacionPallet::Aceptada)
            ->where('resultado', ResultadoValidacionPallet::Aprobado);
    }

    public function inspeccionesSag(): HasMany
    {
        return $this->hasMany(LoteInspeccionSagFolio::class);
    }

    public function autorizacionesSag(): HasMany
    {
        return $this->hasMany(AutorizacionSagFolio::class);
    }

    public function autorizacionesSagActivas(): HasMany
    {
        return $this->autorizacionesSag()->where('activa', true);
    }

    public function historialHabilitacionesAlmacenamiento(): HasMany
    {
        return $this->hasMany(RegistroHabilitacionAlmacenamiento::class);
    }

    public function retencionesOperacionales(): HasMany
    {
        return $this->hasMany(RetencionOperacionalFolio::class);
    }

    public function retencionOperacionalActiva(): HasOne
    {
        return $this->hasOne(RetencionOperacionalFolio::class, 'bloqueo_folio_id');
    }

    public function habilitadoAlmacenamientoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'habilitado_almacenamiento_por_user_id');
    }

    protected function casts(): array
    {
        return [
            'tipo_bulto' => TipoBulto::class,
            'estado_operacional' => EstadoOperacionalFolio::class,
            'condicion_termica' => CondicionTermicaFolio::class,
            'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::class,
            'fuente_habilitacion_almacenamiento' => FuenteHabilitacionAlmacenamiento::class,
            'habilitado_almacenamiento_at' => 'datetime',
            'fecha_ingreso' => 'datetime',
            'activo' => 'boolean',
            'sincronizado_at' => 'datetime',
            'datos_externos' => 'array',
            'fecha_proceso_pt' => 'date',
            'estado_integracion' => EstadoIntegracionFolio::class,
        ];
    }

    /**
     * Los folios de Materiales quedan fuera del control: su inventario cambia de
     * temporada con la migración auditada de Materiales.
     */
    public function temporadaOperacionalId(): ?string
    {
        if ($this->tipo_bulto === TipoBulto::Material || $this->temporada_id === null) {
            return null;
        }

        return (string) $this->temporada_id;
    }
}
