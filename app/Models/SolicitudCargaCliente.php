<?php

namespace App\Models;

use App\Enums\ModalidadEmbarque;
use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Horario de carga pedido desde el portal de clientes. */
#[Fillable([
    'operacion_id', 'payload_hash', 'temporada_id', 'cliente_id', 'codigo', 'fecha', 'hora', 'intervalo_minutos',
    'estado', 'modalidad', 'cantidad_pallets', 'referencia', 'observacion', 'motivo_resolucion', 'embarque_id',
    'solicitado_por_user_id', 'resuelto_por_user_id', 'resuelto_at', 'version',
])]
class SolicitudCargaCliente extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    public const SOLICITADA = 'solicitada';

    public const ACEPTADA = 'aceptada';

    public const RECHAZADA = 'rechazada';

    public const ANULADA = 'anulada';

    protected $table = 'solicitudes_carga_cliente';

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function embarque(): BelongsTo
    {
        return $this->belongsTo(Embarque::class);
    }

    public function solicitadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por_user_id');
    }

    public function resueltoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resuelto_por_user_id');
    }

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'modalidad' => ModalidadEmbarque::class,
            'intervalo_minutos' => 'integer',
            'cantidad_pallets' => 'integer',
            'version' => 'integer',
            'resuelto_at' => 'immutable_datetime',
        ];
    }
}
