<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Concerns\TemporadaPorColumna;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reasignación de un movimiento de envases entre productores CSG de una misma cuenta, completa o parcial.
 * Es historial interno: no crea asientos y no se muestra en los documentos del cliente, que solo ven la
 * atribución final.
 */
#[Fillable([
    'operacion_id',
    'payload_hash',
    'temporada_id',
    'cliente_id',
    'documento_clave',
    'lado',
    'recepcion_romana_id',
    'guia_recepcion_romana_id',
    'guia_despacho_envase_id',
    'numero_documento',
    'titulo',
    'fecha_documento',
    'productor_origen',
    'productor_destino',
    'modalidad',
    'envases',
    'disponibles',
    'motivo',
    'user_id',
    'registrado_at',
])]
class ReasignacionProductorEnvases extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica, TemporadaPorColumna;

    public const COMPLETA = 'completa';

    public const PARCIAL = 'parcial';

    protected $table = 'reasignaciones_productor_envases';

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected function casts(): array
    {
        return [
            'productor_origen' => 'array',
            'productor_destino' => 'array',
            'envases' => 'array',
            'disponibles' => 'array',
            'fecha_documento' => 'datetime',
            'registrado_at' => 'datetime',
        ];
    }
}
