<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Documento REB: lo que se descontó de un almacén según el estándar de lo validado en PT. */
#[Fillable([
    'operacion_id', 'payload_hash', 'codigo', 'temporada_id', 'almacen_material_id', 'desde', 'hasta', 'filtros',
    'estado', 'cajas', 'pallets', 'resumen', 'observacion', 'user_id', 'confirmada_at',
])]
class RebajaEstandarMaterial extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'rebajas_estandar_materiales';

    public function almacen(): BelongsTo
    {
        return $this->belongsTo(AlmacenMaterial::class, 'almacen_material_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function producciones(): HasMany
    {
        return $this->hasMany(ProduccionRebajaEstandar::class, 'rebaja_estandar_material_id');
    }

    public function consumos(): HasMany
    {
        return $this->hasMany(ConsumoRebajaEstandar::class, 'rebaja_estandar_material_id');
    }

    protected function casts(): array
    {
        return [
            'desde' => 'date:Y-m-d',
            'hasta' => 'date:Y-m-d',
            'filtros' => 'array',
            'resumen' => 'array',
            'cajas' => 'integer',
            'pallets' => 'integer',
            'confirmada_at' => 'datetime',
        ];
    }
}
