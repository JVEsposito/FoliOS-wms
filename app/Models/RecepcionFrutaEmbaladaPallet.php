<?php

namespace App\Models;

use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id', 'orden', 'folio_origen', 'tipo_bulto', 'articulo_validacion_id', 'origen_validacion_id', 'embalaje', 'especie', 'variedad', 'csg', 'csp', 'calibre', 'cantidad_cajas', 'fecha_proceso_origen', 'temperatura_pulpa_c', 'condicion_sag_id', 'condicion_sag_personalizada'])]
class RecepcionFrutaEmbaladaPallet extends Model implements PerteneceATemporada
{
    use HasUuids;

    protected $table = 'recepciones_fruta_embalada_pallets';

    public function recepcion(): BelongsTo
    {
        return $this->belongsTo(RecepcionFrutaEmbalada::class, 'recepcion_fruta_embalada_id');
    }

    public function temporadaOperacionalId(): ?string
    {
        return $this->recepcion?->temporada_id;
    }

    protected function casts(): array
    {
        return ['cantidad_cajas' => 'integer', 'orden' => 'integer', 'fecha_proceso_origen' => 'immutable_date', 'temperatura_pulpa_c' => 'decimal:2', 'condicion_sag_personalizada' => 'boolean'];
    }
}
