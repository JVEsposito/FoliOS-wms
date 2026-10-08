<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Concerns\TemporadaPorColumna;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['contenido', 'verificar_cantidad', 'tolerancia_cantidad_pct', 'temporada_id', 'user_id', 'dispositivo_id', 'turno_inicio_at', 'turno_fin_at', 'vence_at', 'estado', 'objetivo', 'version'])]
class VerificacionUbicacion extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica, TemporadaPorColumna;

    protected $table = 'verificaciones_ubicacion';

    public function items(): HasMany
    {
        return $this->hasMany(VerificacionUbicacionItem::class);
    }

    protected function casts(): array
    {
        return [
            'turno_inicio_at' => 'datetime', 'turno_fin_at' => 'datetime',
            'contenido' => 'string', 'verificar_cantidad' => 'boolean', 'tolerancia_cantidad_pct' => 'decimal:3',
            'vence_at' => 'datetime', 'version' => 'integer', 'objetivo' => 'integer',
        ];
    }
}
