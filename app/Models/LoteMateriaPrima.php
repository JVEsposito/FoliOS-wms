<?php

namespace App\Models;

use App\Enums\EstadoLoteMateriaPrima;
use App\Enums\TipoEnvaseRomana;
use App\Enums\TipoProductoMateriaPrima;
use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Concerns\TemporadaPorColumna;
use App\Models\Contracts\PerteneceATemporada;
use App\Services\Validacion\ProyeccionTrazabilidadFolio;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'operacion_id',
    'payload_hash',
    'segmento_validacion_mp_id',
    'recepcion_romana_id',
    'secuencia_creacion',
    'temporada_id',
    'cliente_id',
    'numero_lote',
    'clave_numero_vigente',
    'estado',
    'csg_validacion_id',
    'csg_snapshot',
    'sdp',
    'ggn',
    'fecha_cosecha',
    'predio',
    'especie_validacion_id',
    'especie_snapshot',
    'variedad_validacion_id',
    'variedad_snapshot',
    'calibre_validacion_id',
    'calibre_snapshot',
    'cuartel',
    'tipo_producto',
    'envase_primario',
    'envase_secundario',
    'cantidad_envases_primarios',
    'cantidad_envases_secundarios',
    'kilos_brutos',
    'kilos_netos_calculados',
    'kilos_netos_confirmados',
    'requiere_hidrocooler',
    'version',
    'observacion',
    'creado_por_user_id',
    'actualizado_por_user_id',
    'confirmado_por_user_id',
    'confirmado_at',
    'anulado_por_user_id',
    'anulado_at',
    'motivo_anulacion',
])]
class LoteMateriaPrima extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica, TemporadaPorColumna;

    protected $table = 'lotes_materia_prima';

    protected static function booted(): void
    {
        // Los pallets pueden validarse antes de que su lote se digite: al registrar o
        // modificar el lote se concilian los vínculos de trazabilidad pendientes.
        static::saved(function (LoteMateriaPrima $lote): void {
            if ($lote->wasRecentlyCreated
                || $lote->wasChanged(['numero_lote', 'cliente_id', 'temporada_id', 'estado'])) {
                app(ProyeccionTrazabilidadFolio::class)->conciliarLote($lote);
            }
        });
    }

    public function segmento(): BelongsTo
    {
        return $this->belongsTo(SegmentoValidacionMp::class, 'segmento_validacion_mp_id');
    }

    public function envasesDetalle(): HasMany
    {
        return $this->hasMany(EnvaseLoteMateriaPrima::class, 'lote_materia_prima_id');
    }

    public function recepcion(): BelongsTo
    {
        return $this->belongsTo(RecepcionRomana::class, 'recepcion_romana_id');
    }

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function csg(): BelongsTo
    {
        return $this->belongsTo(CsgValidacion::class, 'csg_validacion_id');
    }

    public function especie(): BelongsTo
    {
        return $this->belongsTo(EspecieValidacion::class, 'especie_validacion_id');
    }

    public function variedad(): BelongsTo
    {
        return $this->belongsTo(VariedadValidacion::class, 'variedad_validacion_id');
    }

    public function calibre(): BelongsTo
    {
        return $this->belongsTo(CalibreValidacion::class, 'calibre_validacion_id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por_user_id');
    }

    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por_user_id');
    }

    public function confirmadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmado_por_user_id');
    }

    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por_user_id');
    }

    public function hidrocooler(): HasOne
    {
        return $this->hasOne(ProcesoHidrocoolerMateriaPrima::class, 'lote_materia_prima_id');
    }

    public function asignacionCamara(): HasOne
    {
        return $this->hasOne(AsignacionCamaraLoteMateriaPrima::class, 'lote_materia_prima_id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(EventoLoteMateriaPrima::class, 'lote_materia_prima_id');
    }

    public function entregasProceso(): HasMany
    {
        return $this->hasMany(EntregaFrutaProceso::class, 'lote_materia_prima_id');
    }

    protected function casts(): array
    {
        return [
            'estado' => EstadoLoteMateriaPrima::class,
            'tipo_producto' => TipoProductoMateriaPrima::class,
            'envase_primario' => TipoEnvaseRomana::class,
            'envase_secundario' => TipoEnvaseRomana::class,
            'fecha_cosecha' => 'date',
            'secuencia_creacion' => 'integer',
            'cantidad_envases_primarios' => 'integer',
            'cantidad_envases_secundarios' => 'integer',
            'kilos_brutos' => 'decimal:3',
            'kilos_netos_calculados' => 'decimal:3',
            'kilos_netos_confirmados' => 'decimal:3',
            'requiere_hidrocooler' => 'boolean',
            'version' => 'integer',
            'confirmado_at' => 'datetime',
            'anulado_at' => 'datetime',
        ];
    }
}
