<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Una lectura de la vista de estándares de planta: correcta (con su copia) o fallida. */
#[Fillable([
    'temporada_id',
    'temporada_origen',
    'conexion_externa_id',
    'operacion_integracion_id',
    'estado',
    'filas',
    'estandares',
    'filas_duplicadas',
    'cabeceras_descartadas',
    'mensaje',
    'user_id',
    'leida_at',
])]
class LecturaEstandarOrigen extends Model
{
    use HasUuids;

    protected $table = 'lecturas_estandares_origen';

    public function estandaresOrigen(): HasMany
    {
        return $this->hasMany(EstandarOrigen::class, 'lectura_estandar_origen_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected function casts(): array
    {
        return [
            'filas' => 'integer',
            'estandares' => 'integer',
            'filas_duplicadas' => 'integer',
            'cabeceras_descartadas' => 'integer',
            'leida_at' => 'datetime',
        ];
    }
}
