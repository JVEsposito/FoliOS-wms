<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Una llamada del asistente a Haiku. Guarda tokens, costo y qué herramienta eligió,
 * nunca el texto de la pregunta ni los resultados. Sirve para los topes y el consumo.
 */
#[Fillable([
    'user_id', 'modelo', 'estado', 'herramienta', 'consulta', 'tokens_entrada', 'tokens_salida',
    'tokens_cache_lectura', 'tokens_cache_escritura', 'costo_usd', 'duracion_ms', 'detalle', 'created_at',
])]
class LlamadaIaAsistente extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    public const UPDATED_AT = null;

    public const OK = 'ok';

    public const ERROR = 'error';

    protected $table = 'asistente_llamadas_ia';

    protected function casts(): array
    {
        return ['costo_usd' => 'float', 'created_at' => 'datetime'];
    }
}
