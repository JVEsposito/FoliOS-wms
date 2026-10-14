<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'trabajo_impresion_material_id',
    'folio_id',
    'folio_salida_reservado_id',
    'numero_folio_snapshot',
    'es_reimpresion',
])]
class FolioTrabajoImpresionMaterial extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'folios_trabajos_impresion_materiales';

    public function trabajo(): BelongsTo
    {
        return $this->belongsTo(TrabajoImpresionMaterial::class, 'trabajo_impresion_material_id');
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    public function folioSalidaReservado(): BelongsTo
    {
        return $this->belongsTo(FolioSalidaReservadoTransformacion::class, 'folio_salida_reservado_id');
    }

    protected function casts(): array
    {
        return ['es_reimpresion' => 'boolean'];
    }
}
