<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de cada pregunta hecha al asistente. Sirve para ampliar el catálogo con lo
 * que no se pudo responder; nunca se borra.
 */
#[Fillable(['user_id', 'texto', 'interprete', 'consulta', 'parametros', 'estado', 'confianza', 'created_at'])]
class PreguntaAsistente extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    public const UPDATED_AT = null;

    protected $table = 'asistente_preguntas';

    protected function casts(): array
    {
        return [
            'parametros' => 'array',
            'confianza' => 'float',
            'created_at' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
