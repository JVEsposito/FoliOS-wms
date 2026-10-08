<?php

namespace App\Models;

use App\Enums\EstadoOrdenTransformacionMaterial;
use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'operacion_id',
    'payload_hash',
    'temporada_id',
    'cliente_id',
    'version_receta_material_id',
    'estado',
    'cantidad_planificada_salida',
    'cantidad_real_salida',
    'linea',
    'turno',
    'fecha_operacional',
    'version',
    'snapshot_receta',
    'faltantes_por_vencimiento',
    'observacion',
    'creado_por_user_id',
    'iniciado_por_user_id',
    'cerrado_por_user_id',
    'cancelado_por_user_id',
    'iniciado_at',
    'cerrado_at',
    'cancelado_at',
    'motivo_cancelacion',
])]
class OrdenTransformacionMaterial extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'ordenes_transformacion_materiales';

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function versionReceta(): BelongsTo
    {
        return $this->belongsTo(VersionRecetaMaterial::class, 'version_receta_material_id');
    }

    public function reservas(): HasMany
    {
        return $this->hasMany(ReservaTransformacionMaterial::class, 'orden_transformacion_material_id');
    }

    public function lotes(): HasMany
    {
        return $this->hasMany(LoteTransformacionMaterial::class, 'orden_transformacion_material_id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(EventoTransformacionMaterial::class, 'orden_transformacion_material_id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por_user_id');
    }

    protected function casts(): array
    {
        return [
            'estado' => EstadoOrdenTransformacionMaterial::class,
            'cantidad_planificada_salida' => 'decimal:3',
            'cantidad_real_salida' => 'decimal:3',
            'fecha_operacional' => 'date',
            'version' => 'integer',
            'snapshot_receta' => 'array',
            'faltantes_por_vencimiento' => 'array',
            'iniciado_at' => 'datetime',
            'cerrado_at' => 'datetime',
            'cancelado_at' => 'datetime',
        ];
    }
}
