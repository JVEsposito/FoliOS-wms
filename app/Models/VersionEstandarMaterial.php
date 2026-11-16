<?php

namespace App\Models;

use App\Enums\OrigenEstandarMaterial;
use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Versión inmutable de un estándar: rige para las cajas embaladas desde `vigente_desde`. */
#[Fillable([
    'estandar_material_id',
    'numero',
    'vigente_desde',
    'origen',
    'motivo',
    'huella_origen',
    'snapshot',
    'creado_por_user_id',
])]
class VersionEstandarMaterial extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'versiones_estandares_materiales';

    public function estandar(): BelongsTo
    {
        return $this->belongsTo(EstandarMaterial::class, 'estandar_material_id');
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(LineaEstandarMaterial::class, 'version_estandar_material_id')->orderBy('orden');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por_user_id');
    }

    protected function casts(): array
    {
        return [
            'numero' => 'integer',
            'vigente_desde' => 'date:Y-m-d',
            'origen' => OrigenEstandarMaterial::class,
            'snapshot' => 'array',
        ];
    }
}
