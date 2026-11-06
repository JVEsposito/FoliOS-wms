<?php

namespace App\Models;

use App\Enums\EstadoValidacionPallet;
use App\Enums\MotivoValidacionPallet;
use App\Enums\ResultadoValidacionPallet;
use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Concerns\TemporadaPorColumna;
use App\Models\Contracts\PerteneceATemporada;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'id', 'operacion_id', 'payload_hash', 'numero_folio', 'numero_intento',
    'tipo_bulto', 'cantidad_cajas', 'linea_proceso', 'turno', 'temporada_id', 'articulo_validacion_id',
    'origen_validacion_id', 'categoria_validacion_id', 'resultado', 'estado', 'motivo', 'observacion',
    'catalogo_version_dispositivo', 'catalogo_version_servidor', 'snapshot',
    'user_id', 'dispositivo_id', 'folio_id', 'validacion_conflicto_id',
    'generado_dispositivo_at', 'recibido_servidor_at',
])]
class ValidacionPallet extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica, TemporadaPorColumna;

    private const TURNO_NOCHE = 'B';

    private const HORA_FIN_NOCHE = 7;

    protected $table = 'validaciones_pallet';

    public function fechaJornada(): ?CarbonImmutable
    {
        $local = $this->generado_dispositivo_at?->toImmutable()->setTimezone(config('app.operational_timezone'));

        if ($local && $this->turno === self::TURNO_NOCHE && $local->hour < self::HORA_FIN_NOCHE) {
            return $local->subDay();
        }

        return $local;
    }

    #[Scope]
    protected function deJornada(Builder $consulta, string $fecha): void
    {
        $inicio = CarbonImmutable::parse($fecha, config('app.operational_timezone'))->startOfDay();
        $siguiente = $inicio->addDay()->startOfDay();

        // El turno B cambia de fecha de jornada a las 07:00. A y los registros sin turno
        // cambian a medianoche. Los límites se convierten a UTC después del cálculo local.
        $consulta->where(fn (Builder $jornada) => $jornada
            ->where(fn (Builder $noche) => $noche
                ->where('turno', self::TURNO_NOCHE)
                ->where('generado_dispositivo_at', '>=', $inicio->setTime(self::HORA_FIN_NOCHE, 0)->utc())
                ->where('generado_dispositivo_at', '<', $siguiente->setTime(self::HORA_FIN_NOCHE, 0)->utc()))
            ->orWhere(fn (Builder $dia) => $dia
                ->where(fn (Builder $turno) => $turno->where('turno', '!=', self::TURNO_NOCHE)->orWhereNull('turno'))
                ->where('generado_dispositivo_at', '>=', $inicio->utc())
                ->where('generado_dispositivo_at', '<', $siguiente->utc())));
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function articulo(): BelongsTo
    {
        return $this->belongsTo(ArticuloValidacion::class, 'articulo_validacion_id');
    }

    public function origen(): BelongsTo
    {
        return $this->belongsTo(OrigenValidacion::class, 'origen_validacion_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(Dispositivo::class);
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaValidacion::class, 'categoria_validacion_id');
    }

    public function conflictoCon(): BelongsTo
    {
        return $this->belongsTo(self::class, 'validacion_conflicto_id');
    }

    public function correcciones(): HasMany
    {
        return $this->hasMany(
            CorreccionValidacionPallet::class,
            'validacion_pallet_id',
        )->latest('corregido_at');
    }

    protected function casts(): array
    {
        return [
            'resultado' => ResultadoValidacionPallet::class,
            'estado' => EstadoValidacionPallet::class,
            'motivo' => MotivoValidacionPallet::class,
            'snapshot' => 'array',
            'linea_proceso' => 'integer',
            'generado_dispositivo_at' => 'datetime',
            'recibido_servidor_at' => 'datetime',
        ];
    }
}
