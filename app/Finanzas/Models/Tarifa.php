<?php

namespace App\Finanzas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Precio de un servicio en una unidad de cobro, para un cliente o como tarifa general
 * (cliente_id nulo), vigente entre dos fechas inclusivas. Nunca se borra: se edita con
 * historial o se anula.
 *
 * @property int $id
 * @property ?string $cliente_id
 * @property ?string $cliente_nombre
 * @property string $servicio
 * @property string $unidad
 * @property string $moneda
 * @property string $precio
 * @property Carbon $vigente_desde
 * @property ?Carbon $vigente_hasta
 * @property ?string $nota
 * @property ?Carbon $anulada_at
 * @property ?string $motivo_anulacion
 * @property ?int $actualizada_por_user_id
 * @property ?string $actualizada_por_nombre
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Tarifa extends Model
{
    public const ESTADO_VIGENTE = 'vigente';

    public const ESTADO_FUTURA = 'futura';

    public const ESTADO_VENCIDA = 'vencida';

    public const ESTADO_ANULADA = 'anulada';

    protected $connection = 'finanzas';

    protected $table = 'fin_tarifas';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'precio' => 'decimal:6',
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
            'anulada_at' => 'datetime',
        ];
    }

    /** @return HasMany<EventoTarifa, $this> */
    public function eventos(): HasMany
    {
        return $this->hasMany(EventoTarifa::class, 'tarifa_id');
    }

    public function general(): bool
    {
        return $this->cliente_id === null;
    }

    public function anulada(): bool
    {
        return $this->anulada_at !== null;
    }

    /** ¿Rige este día? (fecha «Y-m-d» en hora de planta; los extremos son inclusivos). */
    public function cubre(string $fecha): bool
    {
        return ! $this->anulada()
            && $this->vigente_desde->toDateString() <= $fecha
            && ($this->vigente_hasta === null || $this->vigente_hasta->toDateString() >= $fecha);
    }

    public function estado(string $hoy): string
    {
        return match (true) {
            $this->anulada() => self::ESTADO_ANULADA,
            $this->vigente_desde->toDateString() > $hoy => self::ESTADO_FUTURA,
            $this->vigente_hasta !== null && $this->vigente_hasta->toDateString() < $hoy => self::ESTADO_VENCIDA,
            default => self::ESTADO_VIGENTE,
        };
    }
}
