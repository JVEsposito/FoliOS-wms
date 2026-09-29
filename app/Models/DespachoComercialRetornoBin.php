<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['despacho_comercial_id', 'bin_retorno_packing_id', 'folio_definitivo', 'clasificacion', 'kilos_definitivos'])]
class DespachoComercialRetornoBin extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'despachos_comerciales_retorno_bins';

    public function bin(): BelongsTo
    {
        return $this->belongsTo(BinRetornoPacking::class, 'bin_retorno_packing_id');
    }

    protected function casts(): array
    {
        return ['kilos_definitivos' => 'decimal:3'];
    }
}
