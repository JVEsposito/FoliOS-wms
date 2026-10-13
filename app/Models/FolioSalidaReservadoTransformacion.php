<?php

namespace App\Models;

use App\Enums\EstadoFolioSalidaReservado;
use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Número de folio de salida reservado al planificar una orden de transformación.
 * Se imprime antes de producir con la cantidad teórica; al cerrar el lote pasa a ser
 * el folio real. Los que no se usan se anulan.
 */
#[Fillable([
    'orden_transformacion_material_id',
    'secuencia',
    'numero_folio',
    'cantidad_teorica',
    'estado',
    'lote_transformacion_material_id',
    'folio_id',
    'cantidad_real',
    'impresiones',
    'ultima_impresion_at',
    'requiere_reimpresion',
    'motivo_anulacion',
    'anulado_at',
])]
class FolioSalidaReservadoTransformacion extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'folios_salida_reservados_transformacion';

    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenTransformacionMaterial::class, 'orden_transformacion_material_id');
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(LoteTransformacionMaterial::class, 'lote_transformacion_material_id');
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'estado' => EstadoFolioSalidaReservado::class,
            'secuencia' => 'integer',
            'cantidad_teorica' => 'decimal:3',
            'cantidad_real' => 'decimal:3',
            'impresiones' => 'integer',
            'ultima_impresion_at' => 'datetime',
            'requiere_reimpresion' => 'boolean',
            'anulado_at' => 'datetime',
        ];
    }
}
