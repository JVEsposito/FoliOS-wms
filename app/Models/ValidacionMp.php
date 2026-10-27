<?php

namespace App\Models;

use App\Enums\EstadoValidacionMp;
use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Concerns\TemporadaPorColumna;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'recepcion_romana_id', 'temporada_id', 'operacion_toma_id', 'operacion_confirmacion_id',
    'estado', 'validador_user_id', 'dispositivo_id', 'tarjas_verificadas',
    'requiere_segregacion', 'bins_prestados_planta', 'tomada_at', 'validada_at', 'observacion',
])]
class ValidacionMp extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica, TemporadaPorColumna;

    protected $table = 'validaciones_mp';

    public function recepcion(): BelongsTo
    {
        return $this->belongsTo(RecepcionRomana::class, 'recepcion_romana_id');
    }

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function validador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validador_user_id');
    }

    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(Dispositivo::class);
    }

    public function segmentos(): HasMany
    {
        return $this->hasMany(SegmentoValidacionMp::class)->orderBy('secuencia');
    }

    protected function casts(): array
    {
        return [
            'estado' => EstadoValidacionMp::class,
            'tarjas_verificadas' => 'boolean',
            'requiere_segregacion' => 'boolean',
            'bins_prestados_planta' => 'integer',
            'tomada_at' => 'datetime',
            'validada_at' => 'datetime',
        ];
    }
}
