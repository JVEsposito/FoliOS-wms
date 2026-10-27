<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un destino rechazado por el servidor o una tarea sin posición encontrada por la tablet. Un
 * mismo motivo repetido en poco tiempo suma repeticiones sobre la misma fila.
 */
#[Fillable([
    'temporada_id',
    'tarea_movimiento_id',
    'maniobra_operacional_id',
    'folio_id',
    'origen',
    'camara_id',
    'posicion_id',
    'motivo',
    'huella_motivo',
    'planner_version',
    'user_id',
    'dispositivo_id',
    'repeticiones',
    'primera_at',
    'ultima_at',
])]
class IncidenciaDestinoPlanificador extends Model
{
    use HasUuids;

    public const ORIGEN_RECHAZO_SERVIDOR = 'rechazo_servidor';

    public const ORIGEN_SIN_DESTINO_TABLET = 'sin_destino_tablet';

    protected $table = 'incidencias_destino_planificador';

    protected function casts(): array
    {
        return [
            'repeticiones' => 'integer',
            'primera_at' => 'datetime',
            'ultima_at' => 'datetime',
        ];
    }

    public function tareaMovimiento(): BelongsTo
    {
        return $this->belongsTo(TareaMovimiento::class);
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    public function camara(): BelongsTo
    {
        return $this->belongsTo(Camara::class);
    }
}
