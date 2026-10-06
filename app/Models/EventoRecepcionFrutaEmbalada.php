<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['recepcion_fruta_embalada_id', 'operacion_id', 'payload_hash', 'user_id', 'dispositivo_id', 'antes', 'despues'])]
class EventoRecepcionFrutaEmbalada extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'eventos_recepcion_fruta_embalada';

    protected function casts(): array
    {
        return ['antes' => 'array', 'despues' => 'array'];
    }
}
