<?php

namespace App\Models;

use App\Enums\EtapaMedicionPuntoRocio;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Objetivo de una etapa de la cadena de frío: T° del aire y T° más baja de la fruta. */
#[Fillable(['etapa', 'temperatura_aire_c', 'temperatura_fruta_c', 'version', 'actualizado_por_user_id'])]
class PlanCadenaFrioEtapa extends Model
{
    protected $table = 'plan_cadena_frio_etapas';

    protected $primaryKey = 'etapa';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'etapa' => EtapaMedicionPuntoRocio::class,
            'temperatura_aire_c' => 'float',
            'temperatura_fruta_c' => 'float',
            'version' => 'integer',
        ];
    }
}
