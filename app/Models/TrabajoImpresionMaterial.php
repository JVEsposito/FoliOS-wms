<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'transferencia_cliente_material_id',
    'operacion_id',
    'payload_hash',
    'origen',
    'recepcion_material_id',
    'orden_transformacion_material_id',
    'lote_transformacion_material_id',
    'perfil_impresion_etiqueta_id',
    'formato',
    'simbologia',
    'canal',
    'estado',
    'copias',
    'motivo_reimpresion',
    'perfil_snapshot',
    'contenido_snapshot',
    'contenido_hash',
    'solicitado_por_user_id',
    'dispositivo_id',
    'resultado_operacion_id',
    'resultado_payload_hash',
    'destino_impresion_snapshot',
    'bytes_enviados',
    'solicitado_at',
    'enviado_at',
    'ultimo_error',
])]
class TrabajoImpresionMaterial extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'trabajos_impresion_materiales';

    public function recepcion(): BelongsTo
    {
        return $this->belongsTo(RecepcionMaterial::class, 'recepcion_material_id');
    }

    public function ordenTransformacion(): BelongsTo
    {
        return $this->belongsTo(OrdenTransformacionMaterial::class, 'orden_transformacion_material_id');
    }

    public function loteTransformacion(): BelongsTo
    {
        return $this->belongsTo(LoteTransformacionMaterial::class, 'lote_transformacion_material_id');
    }

    public function perfil(): BelongsTo
    {
        return $this->belongsTo(PerfilImpresionEtiqueta::class, 'perfil_impresion_etiqueta_id');
    }

    public function folios(): HasMany
    {
        return $this->hasMany(FolioTrabajoImpresionMaterial::class, 'trabajo_impresion_material_id');
    }

    public function solicitadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por_user_id');
    }

    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(Dispositivo::class);
    }

    protected function casts(): array
    {
        return [
            'copias' => 'integer',
            'perfil_snapshot' => 'array',
            'contenido_snapshot' => 'array',
            'destino_impresion_snapshot' => 'array',
            'bytes_enviados' => 'integer',
            'solicitado_at' => 'datetime',
            'enviado_at' => 'datetime',
        ];
    }
}
