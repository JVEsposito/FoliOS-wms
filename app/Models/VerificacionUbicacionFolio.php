<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['verificacion_ubicacion_item_id', 'folio_esperado_id', 'folio_encontrado_id', 'folio_encontrado_numero', 'cantidad_esperada', 'cantidad_contada', 'unidad_medida', 'otra_posicion_id', 'resultado'])]
class VerificacionUbicacionFolio extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'verificaciones_ubicacion_folios';

    public function item(): BelongsTo
    {
        return $this->belongsTo(VerificacionUbicacionItem::class, 'verificacion_ubicacion_item_id');
    }

    public function temporadaOperacionalId(): ?string
    {
        return $this->item->temporadaOperacionalId();
    }

    protected function casts(): array
    {
        return ['cantidad_esperada' => 'decimal:3', 'cantidad_contada' => 'decimal:3'];
    }
}
