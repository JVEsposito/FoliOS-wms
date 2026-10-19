<?php

namespace App\Finanzas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Historial de un precio: quién lo creó, qué cambió, quién lo cerró al reemplazarlo o lo anuló.
 *
 * @property int $id
 * @property int $tarifa_id
 * @property string $accion
 * @property array<string, mixed> $cambios
 * @property ?string $motivo
 * @property ?int $user_id
 * @property ?string $user_nombre
 * @property Carbon $created_at
 */
class EventoTarifa extends Model
{
    public const CREADA = 'creada';

    public const MODIFICADA = 'modificada';

    /** Otro precio la reemplazó desde una fecha: se le puso término el día anterior. */
    public const CERRADA = 'cerrada';

    public const ANULADA = 'anulada';

    protected $connection = 'finanzas';

    protected $table = 'fin_tarifas_eventos';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'cambios' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Tarifa, $this> */
    public function tarifa(): BelongsTo
    {
        return $this->belongsTo(Tarifa::class, 'tarifa_id');
    }
}
