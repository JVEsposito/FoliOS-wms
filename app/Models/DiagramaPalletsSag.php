<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Concerns\TemporadaPorColumna;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Diagrama de presentación de pallets para inspección SAG/USDA, con numeración continua de cajas. */
#[Fillable(['temporada_id', 'tipo', 'numero', 'csp', 'folio', 'fecha', 'lote', 'especie', 'exportadora', 'pallets',
    'cajas_marcadas', 'total_pallets', 'total_cajas', 'version', 'creado_por_user_id', 'actualizado_por_user_id'])]
class DiagramaPalletsSag extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica, TemporadaPorColumna;

    protected $table = 'diagramas_pallets_sag';

    protected function casts(): array
    {
        return ['pallets' => 'array', 'cajas_marcadas' => 'array', 'fecha' => 'date:Y-m-d',
            'total_pallets' => 'integer', 'total_cajas' => 'integer', 'version' => 'integer'];
    }
}
