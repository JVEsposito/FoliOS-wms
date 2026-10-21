<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Concerns\TemporadaPorColumna;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DocumentoSag extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica, TemporadaPorColumna;

    protected $table = 'documentos_sag';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'entrada' => 'array', 'encabezado' => 'array', 'detalle' => 'array', 'formato_snapshot' => 'array',
            'responsable_snapshot' => 'array', 'planta_snapshot' => 'array',
            'fecha_actividad' => 'immutable_date', 'emitido_at' => 'immutable_datetime',
            'version' => 'integer', 'numero' => 'integer',
        ];
    }
}
