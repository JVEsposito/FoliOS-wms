<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Concerns\TemporadaPorColumna;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'temporada_id',
    'operacion_id',
    'payload_hash',
    'folio_provisional',
    'folio_definitivo',
    'folio_definitivo_vigente',
    'kilos_totales',
    'kilos_totales_definitivos',
    'tipo_resultado_packing_id',
    'nombre_resultado',
    'estado',
    'retorno_packing_legacy_id',
    'registrado_por_user_id',
    'dispositivo_id',
    'registrado_at',
    'operacion_regularizacion_id',
    'payload_regularizacion_hash',
    'regularizado_por_user_id',
    'regularizado_at',
    'operacion_anulacion_id',
    'payload_anulacion_hash',
    'anulado_por_user_id',
    'anulado_at',
    'motivo_anulacion',
    'observacion',
    'despacho_comercial_id',
])]
class BinRetornoPacking extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica, TemporadaPorColumna;

    protected $table = 'bins_retorno_packing';

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function despachoComercial(): BelongsTo
    {
        return $this->belongsTo(DespachoComercialRetorno::class, 'despacho_comercial_id');
    }

    public function origenes(): HasMany
    {
        return $this->hasMany(BinRetornoPackingOrigen::class, 'bin_retorno_packing_id');
    }

    public function modificaciones(): HasMany
    {
        return $this->hasMany(ModificacionBinRetornoPacking::class, 'bin_retorno_packing_id');
    }

    public function ultimaModificacion(): HasOne
    {
        return $this->hasOne(
            ModificacionBinRetornoPacking::class,
            'bin_retorno_packing_id',
        )->latestOfMany('modificado_at');
    }

    public function tipoResultado(): BelongsTo
    {
        return $this->belongsTo(TipoResultadoPacking::class, 'tipo_resultado_packing_id');
    }

    public function retornoLegacy(): BelongsTo
    {
        return $this->belongsTo(RetornoPacking::class, 'retorno_packing_legacy_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por_user_id');
    }

    public function regularizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'regularizado_por_user_id');
    }

    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por_user_id');
    }

    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(Dispositivo::class);
    }

    protected function casts(): array
    {
        return [
            'kilos_totales' => 'decimal:3',
            'kilos_totales_definitivos' => 'decimal:3',
            'registrado_at' => 'datetime',
            'regularizado_at' => 'datetime',
            'anulado_at' => 'datetime',
        ];
    }
}
