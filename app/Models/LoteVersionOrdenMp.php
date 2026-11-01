<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Fila de lote dentro de una versión de orden de proceso: una fila de la PRE-SIF. */
#[Fillable([
    'version_orden_proceso_mp_id',
    'lote_materia_prima_id',
    'secuencia',
    'numero_proceso',
    'bins_programados',
    'totes_programados',
    'kilos_programados',
    'embalaje',
    'kilos_por_caja',
    'cajas_estimadas',
    'mercado_destino',
    'inicio_estimado',
    'termino_estimado',
    'cliente_id',
    'cliente_snapshot',
    'numero_lote_snapshot',
    'especie_snapshot',
    'variedad_snapshot',
    'fecha_cosecha_snapshot',
    'csg_snapshot',
    'productor_snapshot',
    'comuna',
    'provincia',
])]
class LoteVersionOrdenMp extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'lotes_version_orden_mp';

    protected function casts(): array
    {
        return [
            'secuencia' => 'integer',
            'bins_programados' => 'integer',
            'totes_programados' => 'integer',
            'kilos_programados' => 'decimal:3',
            'kilos_por_caja' => 'decimal:3',
            'cajas_estimadas' => 'decimal:1',
            'inicio_estimado' => 'datetime',
            'termino_estimado' => 'datetime',
            'fecha_cosecha_snapshot' => 'date',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(VersionOrdenProcesoMp::class, 'version_orden_proceso_mp_id');
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(LoteMateriaPrima::class, 'lote_materia_prima_id');
    }
}
