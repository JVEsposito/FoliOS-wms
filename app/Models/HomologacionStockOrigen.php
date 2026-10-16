<?php

namespace App\Models;

use App\Models\Concerns\TemporadaPorColumna;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un código del sistema de origen vinculado (o agregado) a un elemento del catálogo de la temporada. */
#[Fillable(['temporada_id', 'dimension', 'contexto', 'valor', 'entidad_id', 'accion', 'creado_por_user_id'])]
class HomologacionStockOrigen extends Model implements PerteneceATemporada
{
    use HasUuids, TemporadaPorColumna;

    protected $table = 'homologaciones_stock_origen';

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por_user_id');
    }
}
