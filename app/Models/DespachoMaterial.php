<?php

namespace App\Models;

use App\Enums\EstadoDespachoMaterial;
use App\Enums\OrigenDespachoMaterial;
use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'temporada_id',
    'codigo',
    'operacion_id',
    'payload_hash',
    'origen',
    'modalidad',
    'asignado_a_user_id',
    'estado',
    'destino_material_id',
    'almacen_origen_id',
    'destino_nombre',
    'destino_centro_costo',
    'observacion',
    'creado_por_user_id',
    'creado_desde_dispositivo_id',
    'completado_at',
    'cancelado_at',
    'cancelacion_operacion_id',
    'cancelacion_payload_hash',
    'cancelado_por_user_id',
    'cancelado_desde_dispositivo_id',
    'cancelacion_motivo',
])]
class DespachoMaterial extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'despachos_materiales';

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function destino(): BelongsTo
    {
        return $this->belongsTo(DestinoMaterial::class, 'destino_material_id');
    }

    public function operacionesRetiro(): HasMany
    {
        return $this->hasMany(OperacionRetiroMaterial::class)->whereNotNull('procesada_at')->orderBy('procesada_at')->orderBy('id');
    }

    public function almacenOrigen(): BelongsTo
    {
        return $this->belongsTo(AlmacenMaterial::class, 'almacen_origen_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(DetalleDespachoMaterial::class, 'despacho_material_id');
    }

    public function asignadoA(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asignado_a_user_id');
    }

    public function asignaciones(): HasMany
    {
        return $this->hasMany(AsignacionDespachoMaterial::class)->orderBy('created_at')->orderBy('id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por_user_id');
    }

    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(Dispositivo::class, 'creado_desde_dispositivo_id');
    }

    public function canceladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelado_por_user_id');
    }

    public function dispositivoCancelacion(): BelongsTo
    {
        return $this->belongsTo(Dispositivo::class, 'cancelado_desde_dispositivo_id');
    }

    protected function casts(): array
    {
        return [
            'origen' => OrigenDespachoMaterial::class,
            'estado' => EstadoDespachoMaterial::class,
            'completado_at' => 'datetime',
            'cancelado_at' => 'datetime',
        ];
    }
}
