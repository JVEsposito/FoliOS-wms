<?php

namespace App\Models;

use App\Enums\EstadoRecepcionFrutaEmbalada;
use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Concerns\TemporadaPorColumna;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['operacion_id', 'temporada_id', 'cliente_id', 'planta_origen_id', 'numero_guia', 'servicio', 'turno', 'validador_id', 'recepcion_at', 'salida_at', 'chofer', 'rut_chofer', 'patente_delantera', 'patente_carro', 'llega_con_prefrio', 'condicion_sag_id', 'observacion', 'estado', 'version', 'creado_por_user_id', 'actualizado_por_user_id', 'formato_rrfe_snapshot'])]
class RecepcionFrutaEmbalada extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica, TemporadaPorColumna;

    protected $table = 'recepciones_fruta_embalada';

    public function pallets(): HasMany
    {
        return $this->hasMany(RecepcionFrutaEmbaladaPallet::class)->orderBy('orden');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(EventoRecepcionFrutaEmbalada::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function plantaOrigen(): BelongsTo
    {
        return $this->belongsTo(PlantaOrigen::class);
    }

    public function validador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validador_id');
    }

    protected function casts(): array
    {
        return ['formato_rrfe_snapshot' => 'array', 'estado' => EstadoRecepcionFrutaEmbalada::class, 'llega_con_prefrio' => 'boolean', 'recepcion_at' => 'immutable_datetime', 'salida_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
