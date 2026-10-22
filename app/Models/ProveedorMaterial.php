<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use App\Services\Materiales\ServicioCorrelativoProveedorMaterial;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'codigo',
    'nombre',
    'codigo_externo',
    'activo',
    'creado_por_user_id',
    'actualizado_por_user_id',
])]
class ProveedorMaterial extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'proveedores_materiales';

    protected static function booted(): void
    {
        static::creating(function (ProveedorMaterial $proveedor): void {
            $proveedor->codigo_interno = app(ServicioCorrelativoProveedorMaterial::class)->siguiente();
            $proveedor->codigo = $proveedor->codigo ?: $proveedor->codigo_interno;
        });

        static::updating(function (ProveedorMaterial $proveedor): void {
            if ($proveedor->isDirty('codigo_interno')) {
                throw new DomainException('El código interno del proveedor es automático y no se puede modificar.');
            }
        });
    }

    public function clientes(): BelongsToMany
    {
        return $this->belongsToMany(
            Cliente::class,
            'clientes_proveedores_materiales',
            'proveedor_material_id',
            'cliente_id',
        )
            ->withPivot(['id', 'activo', 'categorias', 'creado_por_user_id', 'actualizado_por_user_id'])
            ->withTimestamps();
    }

    public function recepciones(): HasMany
    {
        return $this->hasMany(RecepcionMaterial::class, 'proveedor_material_id');
    }

    public function foliosMateriales(): HasMany
    {
        return $this->hasMany(FolioMaterial::class, 'proveedor_material_id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por_user_id');
    }

    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por_user_id');
    }

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }
}
