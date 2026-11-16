<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Estándar de planta tal como llegó en una lectura: uno por empresa y artículo. */
#[Fillable([
    'lectura_estandar_origen_id',
    'cod_empresa',
    'cod_articulo',
    'codigo_estandar',
    'descripcion_estandar',
    'descripcion_articulo',
    'cod_especie',
    'desc_especie',
    'fecha_creacion',
    'fecha_edicion',
    'cabeceras',
    'huella',
    'lineas',
])]
class EstandarOrigen extends Model
{
    use HasUuids;

    protected $table = 'estandares_origen';

    public function lectura(): BelongsTo
    {
        return $this->belongsTo(LecturaEstandarOrigen::class, 'lectura_estandar_origen_id');
    }

    protected function casts(): array
    {
        return [
            'fecha_creacion' => 'datetime',
            'fecha_edicion' => 'datetime',
            'cabeceras' => 'integer',
            'lineas' => 'array',
        ];
    }
}
