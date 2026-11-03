<?php

namespace App\Models;

use App\Enums\TipoEnvaseRomana;
use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'operacion_id',
    'payload_hash',
    'recepcion_romana_id',
    'secuencia',
    'tipo_envase',
    'cantidad_envases',
    'envases',
    'peso_bruto',
    'tara_unitaria_envase',
    'peso_tara',
    'peso_neto',
    'observacion',
    'registrado_por_user_id',
    'pesado_at',
    'operacion_anulacion_id',
    'payload_anulacion_hash',
    'anulado_at',
    'anulado_por_user_id',
    'motivo_anulacion',
])]
class PesajeEnvaseRecepcionRomana extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'pesajes_envases_recepcion_romana';

    public function recepcion(): BelongsTo
    {
        return $this->belongsTo(RecepcionRomana::class, 'recepcion_romana_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por_user_id');
    }

    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por_user_id');
    }

    protected function casts(): array
    {
        return [
            'tipo_envase' => TipoEnvaseRomana::class,
            'cantidad_envases' => 'integer',
            'envases' => 'array',
            'peso_bruto' => 'decimal:3',
            'tara_unitaria_envase' => 'decimal:3',
            'peso_tara' => 'decimal:3',
            'peso_neto' => 'decimal:3',
            'pesado_at' => 'datetime',
            'anulado_at' => 'datetime',
        ];
    }

    /**
     * Detalle por tipo de envase de la tanda. Las lecturas anteriores al pesaje
     * con varios tipos tenían uno solo: su detalle es la propia lectura.
     *
     * @return list<array{tipo_envase: string, cantidad: int, tara_unitaria: float, peso_tara: float, peso_neto: float}>
     */
    public function lineas(): array
    {
        if ($this->envases) {
            return array_map(fn (array $linea): array => [
                'tipo_envase' => (string) $linea['tipo_envase'],
                'cantidad' => (int) $linea['cantidad'],
                'tara_unitaria' => (float) $linea['tara_unitaria'],
                'peso_tara' => (float) $linea['peso_tara'],
                'peso_neto' => (float) $linea['peso_neto'],
            ], $this->envases);
        }

        return [[
            'tipo_envase' => $this->tipo_envase->value,
            'cantidad' => (int) $this->cantidad_envases,
            'tara_unitaria' => (float) $this->tara_unitaria_envase,
            'peso_tara' => (float) $this->peso_tara,
            'peso_neto' => (float) $this->peso_neto,
        ]];
    }

    /** La temporada es la de la recepción de Romana. */
    public function temporadaOperacionalId(): ?string
    {
        return $this->recepcion?->temporadaOperacionalId();
    }
}
