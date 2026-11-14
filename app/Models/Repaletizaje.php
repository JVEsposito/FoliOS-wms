<?php

namespace App\Models;

use App\Models\Contracts\PerteneceATemporada;
use App\Services\Jornadas\CalendarioOperativo;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'operacion_id',
    'payload_hash',
    'codigo',
    'modalidad',
    'tipo_resultado',
    'estrategia_folio',
    'folio_resultante_id',
    'folio_conservado_id',
    'cantidad_objetivo',
    'cantidad_resultante',
    'kilos_entrada', 'kilos_salida', 'kilos_merma', 'motivo_merma',
    'condicion_termica',
    'campos_mix',
    'snapshot',
    'estado',
    'observacion',
    'turno',
    'fecha_operacional',
    'user_id',
    'dispositivo_id',
    'confirmado_at',
    'operacion_anulacion_id',
    'anulado_por_user_id',
    'anulado_at',
    'motivo_anulacion',
])]
class Repaletizaje extends Model implements PerteneceATemporada
{
    use HasUuids;

    public function folioResultante(): BelongsTo
    {
        return $this->belongsTo(Folio::class, 'folio_resultante_id');
    }

    public function folioConservado(): BelongsTo
    {
        return $this->belongsTo(Folio::class, 'folio_conservado_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(RepaletizajeDetalle::class)->orderBy('orden');
    }

    public function resultados(): HasMany
    {
        return $this->hasMany(RepaletizajeResultado::class)->orderBy('orden');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(Dispositivo::class);
    }

    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por_user_id');
    }

    public function turnoOperativo(): ?string
    {
        return app(CalendarioOperativo::class)->datos($this->confirmado_at)['turno_operativo'];
    }

    protected function casts(): array
    {
        return [
            'cantidad_objetivo' => 'integer',
            'cantidad_resultante' => 'integer',
            'campos_mix' => 'array',
            'snapshot' => 'array',
            'confirmado_at' => 'datetime',
            'fecha_operacional' => 'date',
            'anulado_at' => 'datetime',
        ];
    }

    /** La temporada es la del folio resultante o, si no existe, la del folio conservado. */
    public function temporadaOperacionalId(): ?string
    {
        return ($this->folioResultante ?? $this->folioConservado)?->temporadaOperacionalId();
    }
}
