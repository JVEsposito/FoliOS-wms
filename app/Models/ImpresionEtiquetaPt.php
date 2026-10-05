<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Concerns\TemporadaPorColumna;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['operacion_id', 'payload_hash', 'temporada_id', 'user_id', 'tipo', 'copias', 'motivo_reimpresion', 'etiquetas_snapshot'])]
class ImpresionEtiquetaPt extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica, TemporadaPorColumna;

    protected $table = 'impresiones_etiquetas_pt';

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function folios(): BelongsToMany
    {
        return $this->belongsToMany(Folio::class, 'impresion_etiqueta_pt_folios', 'impresion_id', 'folio_id');
    }

    protected function casts(): array
    {
        return ['etiquetas_snapshot' => 'array', 'copias' => 'integer'];
    }
}
