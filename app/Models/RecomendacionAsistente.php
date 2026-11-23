<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Una recomendación pedida a Haiku: datos enviados, respuesta y si se mostró. */
#[Fillable(['user_id', 'llamada_ia_id', 'consulta', 'datos_enviados', 'respuesta', 'estado', 'motivo', 'created_at'])]
class RecomendacionAsistente extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    public const UPDATED_AT = null;

    public const MOSTRADA = 'mostrada';

    public const RECHAZADA = 'rechazada';

    public const NO_DISPONIBLE = 'no_disponible';

    protected $table = 'asistente_recomendaciones';

    protected function casts(): array
    {
        return ['datos_enviados' => 'array', 'created_at' => 'datetime'];
    }
}
