<?php

namespace App\Models;

use App\Enums\TipoAlmacenMaterial;
use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'codigo',
    'nombre',
    'tipo',
    'centro_costo',
    'requiere_ubicacion_fisica',
    'descripcion',
    'codigo_externo',
    'origen_sistema',
    'sincronizado_at',
    'activo',
    'creado_por_user_id',
    'actualizado_por_user_id',
    'version_bodega',
])]
class AlmacenMaterial extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    public const CODIGO_BODEGA_CENTRAL = 'BOD-CENTRAL';

    protected $table = 'destinos_materiales';

    public function saldos(): HasMany
    {
        return $this->hasMany(SaldoMaterialAlmacen::class, 'almacen_material_id');
    }

    protected function casts(): array
    {
        return [
            'tipo' => TipoAlmacenMaterial::class,
            'requiere_ubicacion_fisica' => 'boolean',
            'activo' => 'boolean',
            'version_bodega' => 'integer',
            'sincronizado_at' => 'datetime',
        ];
    }
}
