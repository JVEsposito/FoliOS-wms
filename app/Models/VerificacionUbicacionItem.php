<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['verificacion_ubicacion_id', 'posicion_id', 'folio_esperado_id', 'ubicacion_asignada_id', 'folio_encontrado_id', 'folio_encontrado_numero', 'resultado', 'verificada_at', 'dispositivo_id', 'operacion_id', 'respuesta_payload_hash', 'version'])]
class VerificacionUbicacionItem extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'verificaciones_ubicacion_items';

    public function ronda(): BelongsTo
    {
        return $this->belongsTo(VerificacionUbicacion::class, 'verificacion_ubicacion_id');
    }

    public function temporadaOperacionalId(): ?string
    {
        return $this->ronda()->value('temporada_id');
    }

    public function posicion(): BelongsTo
    {
        return $this->belongsTo(Posicion::class);
    }

    public function incidencia(): HasOne
    {
        return $this->hasOne(IncidenciaVerificacionUbicacion::class);
    }

    protected function casts(): array
    {
        return ['verificada_at' => 'datetime', 'version' => 'integer'];
    }
}
