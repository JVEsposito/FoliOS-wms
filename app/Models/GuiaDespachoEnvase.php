<?php

namespace App\Models;

use App\Enums\EstadoGuiaDespachoEnvase;
use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Concerns\TemporadaPorColumna;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'operacion_id', 'payload_hash', 'numero', 'numero_guia_despacho', 'temporada_id',
    'temporada_codigo_snapshot', 'temporada_nombre_snapshot',
    'cliente_id', 'cliente_codigo_snapshot', 'cliente_nombre_snapshot', 'estado', 'salida_at',
    'patente_camion', 'rut_conductor', 'nombre_conductor', 'observacion', 'version',
    'creado_por_user_id', 'confirmado_por_user_id', 'anulado_por_user_id', 'cancelado_por_user_id',
    'confirmado_at', 'cancelado_at', 'anulado_at', 'motivo_anulacion', 'motivo_cancelacion',
    'documento_snapshot', 'documento_hash', 'documento_generado_at',
])]
class GuiaDespachoEnvase extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica, TemporadaPorColumna;

    protected $table = 'guias_despacho_envases';

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(DetalleGuiaDespachoEnvase::class);
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por_user_id');
    }

    public function confirmadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmado_por_user_id');
    }

    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por_user_id');
    }

    public function canceladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelado_por_user_id');
    }

    public function archivos(): HasMany
    {
        return $this->hasMany(ArchivoGuiaDespachoEnvase::class)->orderByDesc('subido_at');
    }

    /** Archivo de la guía emitida que está vigente; los reemplazados quedan en archivos(). */
    public function archivoVigente(): HasOne
    {
        return $this->hasOne(ArchivoGuiaDespachoEnvase::class)->whereNull('reemplazado_at');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(EventoGuiaDespachoEnvase::class)
            ->orderBy('ocurrido_at');
    }

    protected function casts(): array
    {
        return [
            'estado' => EstadoGuiaDespachoEnvase::class,
            'salida_at' => 'datetime',
            'confirmado_at' => 'datetime',
            'cancelado_at' => 'datetime',
            'anulado_at' => 'datetime',
            'documento_snapshot' => 'array',
            'documento_generado_at' => 'datetime',
            'version' => 'integer',
        ];
    }
}
