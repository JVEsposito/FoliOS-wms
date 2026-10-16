<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Archivo de la guía de despacho emitida en el sistema de facturación, subido como respaldo. */
#[Fillable([
    'id', 'guia_despacho_envase_id', 'ruta', 'nombre_original', 'mime', 'bytes', 'sha256',
    'subido_por_user_id', 'subido_at', 'reemplazado_at',
])]
class ArchivoGuiaDespachoEnvase extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'archivos_guias_despacho_envases';

    public function guia(): BelongsTo
    {
        return $this->belongsTo(GuiaDespachoEnvase::class, 'guia_despacho_envase_id');
    }

    public function subidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subido_por_user_id');
    }

    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'subido_at' => 'datetime',
            'reemplazado_at' => 'datetime',
        ];
    }
}
