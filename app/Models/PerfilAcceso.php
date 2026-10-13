<?php

namespace App\Models;

use App\Enums\RolUsuario;
use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'codigo',
    'nombre',
    'descripcion',
    'rol_base',
    'modulos',
    'modulos_tablet',
    'pantalla_inicio',
    'activo',
    'predeterminado',
    'protegido',
    'creado_por_user_id',
    'actualizado_por_user_id',
])]
class PerfilAcceso extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'perfiles_acceso';

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rol_base' => RolUsuario::class,
            'modulos' => 'array',
            'modulos_tablet' => 'array',
            'activo' => 'boolean',
            'predeterminado' => 'boolean',
            'protegido' => 'boolean',
        ];
    }
}
