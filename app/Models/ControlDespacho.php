<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Concerns\TemporadaPorColumna;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Planilla POEMP R-24 de una carga: la registra el equipo de consolidación en el andén. */
class ControlDespacho extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica, TemporadaPorColumna;

    public const BORRADOR = 'borrador';

    public const CERRADO = 'cerrado';

    protected $table = 'controles_despacho';

    protected $guarded = [];

    public function carga(): BelongsTo
    {
        return $this->belongsTo(Carga::class);
    }

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function posiciones(): HasMany
    {
        return $this->hasMany(PosicionControlDespacho::class)->orderBy('posicion')->orderBy('registrado_at');
    }

    public function fotos(): HasMany
    {
        return $this->hasMany(FotoControlDespacho::class)->orderBy('tipo')->orderBy('orden');
    }

    public function iniciadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'iniciado_por_user_id');
    }

    public function cerradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrado_por_user_id');
    }

    public function estaCerrado(): bool
    {
        return $this->estado === self::CERRADO;
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'encabezado' => 'array',
            'inspeccion' => 'array',
            'cierre' => 'array',
            'planilla_cerrada' => 'array',
            'reaperturas' => 'array',
            'iniciado_at' => 'datetime',
            'cerrado_at' => 'datetime',
        ];
    }
}
