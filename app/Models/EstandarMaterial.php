<?php

namespace App\Models;

use App\Enums\OrigenEstandarMaterial;
use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Concerns\TemporadaPorColumna;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Materiales por caja de un embalaje PT de un cliente, en una temporada y con un origen. */
#[Fillable([
    'temporada_id',
    'cliente_id',
    'envase_validacion_id',
    'origen',
    'codigo',
    'descripcion',
    'codigo_articulo',
    'descripcion_articulo',
    'especie',
    'activo',
    'version',
    'estandar_base_id',
    'creado_por_user_id',
    'actualizado_por_user_id',
])]
class EstandarMaterial extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica, TemporadaPorColumna;

    protected $table = 'estandares_materiales';

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function envase(): BelongsTo
    {
        return $this->belongsTo(EnvaseValidacion::class, 'envase_validacion_id');
    }

    public function base(): BelongsTo
    {
        return $this->belongsTo(self::class, 'estandar_base_id');
    }

    public function versiones(): HasMany
    {
        return $this->hasMany(VersionEstandarMaterial::class, 'estandar_material_id');
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
        return [
            'origen' => OrigenEstandarMaterial::class,
            'activo' => 'boolean',
            'version' => 'integer',
        ];
    }
}
