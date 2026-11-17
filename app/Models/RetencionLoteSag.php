<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Concerns\TemporadaPorColumna;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['temporada_id', 'cliente_id', 'numero_lote', 'lote_materia_prima_id', 'estado', 'fecha_deteccion',
    'observacion', 'referencia_informe', 'todos_destinos', 'destinos', 'resolucion', 'definitivo',
    'instruccion_segregacion', 'version', 'creado_por_user_id', 'actualizado_por_user_id'])]
class RetencionLoteSag extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica, TemporadaPorColumna;

    protected $table = 'retenciones_lote_sag';

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    protected function casts(): array
    {
        return ['destinos' => 'array', 'todos_destinos' => 'boolean', 'definitivo' => 'boolean',
            'version' => 'integer', 'fecha_deteccion' => 'date:Y-m-d'];
    }
}
