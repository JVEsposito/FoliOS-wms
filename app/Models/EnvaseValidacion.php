<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'especie_validacion_id',
    'cliente_validacion_id',
    'nombre',
    'codigo_externo',
    'kilos_netos_por_caja',
    'activo',
])]
class EnvaseValidacion extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'envases_validacion';

    public function especie(): BelongsTo
    {
        return $this->belongsTo(EspecieValidacion::class, 'especie_validacion_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(ClienteValidacion::class, 'cliente_validacion_id');
    }

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'kilos_netos_por_caja' => 'decimal:4'];
    }
}
