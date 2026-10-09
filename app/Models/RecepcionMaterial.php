<?php

namespace App\Models;

use App\Enums\EstadoRecepcionMaterial;
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
    'proveedor_material_id',
    'numero_guia_despacho',
    'fecha_documento',
    'orden_compra',
    'patente',
    'transportista',
    'estado',
    'version',
    'observacion',
    'snapshot_confirmacion',
    'confirmacion_operacion_id',
    'confirmacion_payload_hash',
    'creado_por_user_id',
    'confirmado_por_user_id',
    'confirmado_at',
    'anulacion_operacion_id',
    'anulacion_payload_hash',
    'anulado_por_user_id',
    'anulado_at',
    'motivo_anulacion',
])]
class RecepcionMaterial extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'recepciones_materiales';

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(ProveedorMaterial::class, 'proveedor_material_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(DetalleRecepcionMaterial::class, 'recepcion_material_id');
    }

    public function fotos(): HasMany
    {
        return $this->hasMany(FotoRecepcionMaterial::class, 'recepcion_material_id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(EventoRecepcionMaterial::class, 'recepcion_material_id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por_user_id');
    }

    public function confirmadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmado_por_user_id');
    }

    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por_user_id');
    }

    protected function casts(): array
    {
        return [
            'fecha_documento' => 'date',
            'estado' => EstadoRecepcionMaterial::class,
            'snapshot_confirmacion' => 'array',
            'confirmado_at' => 'datetime',
            'anulado_at' => 'datetime',
        ];
    }
}
