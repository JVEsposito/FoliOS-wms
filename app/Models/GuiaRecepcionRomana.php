<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['recepcion_romana_id', 'temporada_id', 'cliente_id', 'secuencia', 'numero_guia_despacho', 'envases', 'envases_validados', 'vigente'])]
class GuiaRecepcionRomana extends Model
{
    use HasUuids;

    protected $table = 'guias_recepcion_romana';

    public function recepcion(): BelongsTo
    {
        return $this->belongsTo(RecepcionRomana::class, 'recepcion_romana_id');
    }

    protected function casts(): array
    {
        return ['secuencia' => 'integer', 'envases' => 'array', 'envases_validados' => 'array', 'vigente' => 'integer'];
    }
}
