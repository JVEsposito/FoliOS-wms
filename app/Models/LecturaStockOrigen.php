<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cada lectura del stock de origen: origen, fecha, resultado y error (regla 16.6). */
#[Fillable([
    'ambito', 'conexion_externa_id', 'tabla', 'bodega', 'temporada_origen', 'estado', 'causa', 'mensaje',
    'filas', 'pallets', 'nuevos', 'modificados', 'retirados', 'solicitada_por_user_id', 'iniciada_at', 'terminada_at',
])]
class LecturaStockOrigen extends Model
{
    use HasUuids;

    protected $table = 'lecturas_stock_origen';

    public function conexion(): BelongsTo
    {
        return $this->belongsTo(ConexionExterna::class, 'conexion_externa_id');
    }

    public function solicitadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitada_por_user_id');
    }

    protected function casts(): array
    {
        return [
            'filas' => 'integer',
            'pallets' => 'integer',
            'nuevos' => 'integer',
            'modificados' => 'integer',
            'retirados' => 'integer',
            'iniciada_at' => 'datetime',
            'terminada_at' => 'datetime',
        ];
    }
}
