<?php

namespace App\Finanzas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Decisión sobre una diferencia de un mes cerrado, por línea (cliente y precio). Aceptada, se
 * cobra (o se abona, si es negativa) en el primer mes abierto; descartada, queda con su motivo.
 * Se puede deshacer mientras el mes de destino siga abierto.
 *
 * @property int $id
 * @property int $cierre_id
 * @property string $mes
 * @property string $cliente_id
 * @property string $cliente_nombre
 * @property int $tarifa_id
 * @property string $servicio
 * @property string $unidad
 * @property string $moneda
 * @property string $simbolo
 * @property int $decimales
 * @property ?string $precio_anterior
 * @property ?string $precio
 * @property string $cantidad
 * @property int $dias
 * @property string $monto
 * @property string $decision
 * @property ?string $motivo
 * @property ?string $mes_destino
 * @property Carbon $decidida_at
 * @property ?int $decidida_por_user_id
 * @property ?string $decidida_por_nombre
 * @property ?Carbon $anulada_at
 * @property ?int $anulada_por_user_id
 * @property ?string $anulada_por_nombre
 * @property ?string $motivo_anulacion
 */
class Diferencia extends Model
{
    public const ACEPTADA = 'aceptada';

    public const DESCARTADA = 'descartada';

    protected $connection = 'finanzas';

    protected $table = 'fin_diferencias';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tarifa_id' => 'integer',
            'decimales' => 'integer',
            'dias' => 'integer',
            'decidida_at' => 'datetime',
            'anulada_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Cierre, $this> */
    public function cierre(): BelongsTo
    {
        return $this->belongsTo(Cierre::class, 'cierre_id');
    }

    /** @return HasMany<AporteDiferencia, $this> */
    public function aportes(): HasMany
    {
        return $this->hasMany(AporteDiferencia::class, 'diferencia_id');
    }

    public function anulada(): bool
    {
        return $this->anulada_at !== null;
    }

    public function aceptada(): bool
    {
        return $this->decision === self::ACEPTADA;
    }
}
