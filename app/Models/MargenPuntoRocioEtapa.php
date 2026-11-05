<?php

namespace App\Models;

use App\Enums\EtapaMedicionPuntoRocio;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Margen mínimo (superficie − rocío) que supervisión define para una etapa. */
#[Fillable(['etapa', 'margen_minimo_c', 'version', 'actualizado_por_user_id'])]
class MargenPuntoRocioEtapa extends Model
{
    protected $table = 'margenes_punto_rocio_etapa';

    protected $primaryKey = 'etapa';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'etapa' => EtapaMedicionPuntoRocio::class,
            'margen_minimo_c' => 'float',
            'version' => 'integer',
        ];
    }
}
