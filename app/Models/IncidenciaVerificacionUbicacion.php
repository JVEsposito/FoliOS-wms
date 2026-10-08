<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Concerns\TemporadaPorColumna;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['verificacion_ubicacion_folio_id', 'cantidad_esperada', 'cantidad_contada', 'unidad_medida', 'resuelto_por_user_id', 'resuelta_at', 'resolucion', 'verificacion_ubicacion_item_id', 'temporada_id', 'camara_id', 'posicion_id', 'folio_esperado_id', 'folio_encontrado_id', 'folio_encontrado_numero', 'otra_posicion_id', 'reportado_por_user_id', 'dispositivo_id', 'tipo', 'estado', 'reportada_at'])]
class IncidenciaVerificacionUbicacion extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica, TemporadaPorColumna;

    protected $table = 'incidencias_verificacion_ubicacion';

    public function posicion(): BelongsTo
    {
        return $this->belongsTo(Posicion::class);
    }

    public function camara(): BelongsTo
    {
        return $this->belongsTo(Camara::class);
    }

    public function folioEsperado(): BelongsTo
    {
        return $this->belongsTo(Folio::class, 'folio_esperado_id');
    }

    public function folioEncontrado(): BelongsTo
    {
        return $this->belongsTo(Folio::class, 'folio_encontrado_id');
    }

    public function otraPosicion(): BelongsTo
    {
        return $this->belongsTo(Posicion::class, 'otra_posicion_id');
    }

    public function reportadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reportado_por_user_id');
    }

    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(Dispositivo::class);
    }

    protected function casts(): array
    {
        return ['reportada_at' => 'datetime', 'resuelta_at' => 'datetime', 'cantidad_esperada' => 'decimal:3', 'cantidad_contada' => 'decimal:3'];
    }
}
