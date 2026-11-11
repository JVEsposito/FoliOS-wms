<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Concerns\TemporadaPorColumna;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cambio de atributos de un folio PT hecho por administración, con su motivo. */
#[Fillable([
    'temporada_id', 'folio_id', 'numero_folio', 'operacion_id', 'payload_hash', 'tipo',
    'antes', 'despues', 'motivo', 'carga_id', 'carga_codigo', 'user_id',
])]
class CambioAtributosPt extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica, TemporadaPorColumna;

    public const PRODUCTO = 'producto';

    public const CONDICION_TERMICA = 'condicion_termica';

    protected $table = 'cambios_atributos_pt';

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected function casts(): array
    {
        return ['antes' => 'array', 'despues' => 'array'];
    }
}
