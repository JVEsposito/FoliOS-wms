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
 * Reasignación de una guía completa de la cuenta de envases a otro productor o cuenta, o su deshacer.
 * La vigente de cada guía dice dónde están hoy sus envases; las demás son historial.
 */
#[Fillable([
    'operacion_id',
    'payload_hash',
    'temporada_id',
    'documento_clave',
    'lado',
    'recepcion_romana_id',
    'guia_recepcion_romana_id',
    'guia_despacho_envase_id',
    'numero_documento',
    'cliente_origen_id',
    'productor_origen',
    'cliente_destino_id',
    'productor_destino',
    'deshace',
    'vigente',
    'envases',
    'motivo',
    'user_id',
    'registrado_at',
])]
class ReasignacionEnvases extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica, TemporadaPorColumna;

    protected $table = 'reasignaciones_envases';

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function clienteOrigen(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_origen_id');
    }

    public function clienteDestino(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_destino_id');
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
            'deshace' => 'boolean',
            'vigente' => 'boolean',
            'registrado_at' => 'datetime',
        ];
    }
}
