<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'id',
    'despacho_material_id',
    'user_id',
    'dispositivo_id',
    'payload_hash',
    'procesada_at',
    'fecha_despacho',
    'provisional',
    'registro_retroactivo',
    'numero_documento',
    'fecha_documento',
    'motivo_registro_tardio',
    'fecha_limite_regularizacion',
    'autorizado_por_user_id',
    'autorizado_at',
    'motivo_autorizacion',
    'regularizado_por_user_id',
    'regularizado_at',
    'motivo_regularizacion',
    'version',
    'snapshot_registro',
])]
class OperacionRetiroMaterial extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'operaciones_retiro_materiales';

    public function despacho(): BelongsTo
    {
        return $this->belongsTo(DespachoMaterial::class, 'despacho_material_id');
    }

    public function retiros(): HasMany
    {
        return $this->hasMany(RetiroMaterial::class, 'operacion_retiro_material_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function autorizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autorizado_por_user_id');
    }

    public function regularizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'regularizado_por_user_id');
    }

    public function regularizaciones(): HasMany
    {
        return $this->hasMany(RegularizacionDespachoMaterial::class, 'operacion_retiro_material_id')->orderBy('ocurrido_at')->orderBy('id');
    }

    public function scopeRegularizacion(Builder $query, string $filtro): void
    {
        match ($filtro) {
            'pendiente' => $query->where('provisional', true)->whereNull('regularizado_at'),
            'vencida' => $query->where('provisional', true)->whereNull('regularizado_at')->where('fecha_limite_regularizacion', '<', now()),
            'regularizada' => $query->whereNotNull('regularizado_at'),
            'retroactiva' => $query->where('registro_retroactivo', true),
            default => null,
        };
    }

    protected function casts(): array
    {
        return ['procesada_at' => 'datetime', 'fecha_despacho' => 'datetime', 'provisional' => 'boolean', 'registro_retroactivo' => 'boolean', 'fecha_documento' => 'date', 'fecha_limite_regularizacion' => 'datetime', 'autorizado_at' => 'datetime', 'regularizado_at' => 'datetime', 'version' => 'integer', 'snapshot_registro' => 'array'];
    }
}
