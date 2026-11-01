<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'operacion_id',
    'payload_hash',
    'lote_materia_prima_id',
    'orden_proceso_mp_id',
    'version_orden_proceso_mp_id',
    'fuera_de_orden',
    'autorizado_por_user_id',
    'asignacion_camara_lote_id',
    'camara_id',
    'cantidad_envases',
    'kilos_enviados',
    'saldo_anterior',
    'saldo_posterior',
    'linea_proceso',
    'turno',
    'numero_orden',
    'observacion',
    'entregado_por_user_id',
    'dispositivo_id',
    'entregado_at',
    'operacion_anulacion_id',
    'anulado_por_user_id',
    'anulado_at',
    'motivo_anulacion',
])]
class EntregaFrutaProceso extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'entregas_fruta_proceso';

    public function lote(): BelongsTo
    {
        return $this->belongsTo(LoteMateriaPrima::class, 'lote_materia_prima_id');
    }

    public function asignacionCamara(): BelongsTo
    {
        return $this->belongsTo(
            AsignacionCamaraLoteMateriaPrima::class,
            'asignacion_camara_lote_id',
        );
    }

    public function ordenProceso(): BelongsTo
    {
        return $this->belongsTo(OrdenProcesoMp::class, 'orden_proceso_mp_id');
    }

    public function autorizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autorizado_por_user_id');
    }

    public function camara(): BelongsTo
    {
        return $this->belongsTo(Camara::class);
    }

    public function entregadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entregado_por_user_id');
    }

    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(Dispositivo::class);
    }

    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por_user_id');
    }

    public function retornos(): BelongsToMany
    {
        return $this->belongsToMany(
            RetornoPacking::class,
            'retorno_packing_entregas',
            'entrega_fruta_proceso_id',
            'retorno_packing_id',
        )->withPivot('cierra_entrega')->withTimestamps();
    }

    protected function casts(): array
    {
        return [
            'cantidad_envases' => 'integer',
            'kilos_enviados' => 'decimal:3',
            'saldo_anterior' => 'integer',
            'saldo_posterior' => 'integer',
            'fuera_de_orden' => 'boolean',
            'entregado_at' => 'datetime',
            'anulado_at' => 'datetime',
        ];
    }

    /** La temporada es la del lote de materia prima. */
    public function temporadaOperacionalId(): ?string
    {
        return $this->lote?->temporadaOperacionalId();
    }
}
