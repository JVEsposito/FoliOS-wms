<?php

namespace App\Models;

use App\Enums\EstadoRevisionMovimientoEnvase;
use App\Enums\PropiedadEnvase;
use App\Enums\TipoEnvaseRomana;
use App\Enums\TipoMovimientoEnvase;
use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Concerns\TemporadaPorColumna;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'operacion_id',
    'temporada_id',
    'cliente_id',
    'recepcion_romana_id',
    'documento_tipo',
    'documento_id',
    'numero_documento',
    'tipo_movimiento',
    'tipo_envase',
    'cantidad',
    'signo_cuenta',
    'signo_existencia',
    'propiedad',
    'movimiento_origen_id',
    'ocurrido_at',
    'ingreso_at',
    'salida_at',
    'estado_revision',
    'creado_por_user_id',
    'datos',
])]
class MovimientoEnvase extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica, TemporadaPorColumna;

    protected $table = 'movimientos_envases';

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function recepcion(): BelongsTo
    {
        return $this->belongsTo(RecepcionRomana::class, 'recepcion_romana_id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por_user_id');
    }

    public function revisiones(): HasMany
    {
        return $this->hasMany(RevisionMovimientoEnvase::class);
    }

    public function correccionesCuenta(): HasMany
    {
        return $this->hasMany(self::class, 'movimiento_origen_id')->where('tipo_movimiento', TipoMovimientoEnvase::CorreccionCuenta->value);
    }

    public function correccionesInvalidantes(): HasMany
    {
        return $this->hasMany(self::class, 'movimiento_origen_id')->whereIn('tipo_movimiento', [
            TipoMovimientoEnvase::CorreccionCantidad->value, TipoMovimientoEnvase::CorreccionPropiedad->value,
            TipoMovimientoEnvase::ReversionAjusteCuenta->value, TipoMovimientoEnvase::AnulacionRecepcion->value]);
    }

    protected function casts(): array
    {
        return [
            'tipo_movimiento' => TipoMovimientoEnvase::class,
            'tipo_envase' => TipoEnvaseRomana::class,
            'propiedad' => PropiedadEnvase::class,
            'estado_revision' => EstadoRevisionMovimientoEnvase::class,
            'cantidad' => 'integer',
            'signo_cuenta' => 'integer',
            'signo_existencia' => 'integer',
            'ocurrido_at' => 'datetime',
            'ingreso_at' => 'datetime',
            'salida_at' => 'datetime',
            'datos' => 'array',
        ];
    }
}
