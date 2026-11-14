<?php

namespace App\Models;

use App\Enums\EstadoValidacionPallet;
use App\Enums\MotivoValidacionPallet;
use App\Enums\ResultadoValidacionPallet;
use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Concerns\TemporadaPorColumna;
use App\Models\Contracts\PerteneceATemporada;
use App\Services\Jornadas\CalendarioOperativo;
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

    public static function fechaJornadaVigente(): string
    {
        return app(CalendarioOperativo::class)->fecha(inicioHistorico: '07:00');
    }

    public function fechaJornada(): ?CarbonImmutable
    {
        $instante = $this->generado_dispositivo_at;
        if (! $instante) {
            return null;
        }

        $calendario = app(CalendarioOperativo::class);
        $vigencia = $calendario->primeraVigencia();
        if ($vigencia && $vigencia->lte($instante)) {
            $fecha = $calendario->fecha($instante);
        } else {
            $local = $instante->toImmutable()->setTimezone($calendario->zona());
            $fecha = ($this->turno === self::TURNO_NOCHE && $local->hour < self::HORA_FIN_NOCHE ? $local->subDay() : $local)->toDateString();
        }

        return CarbonImmutable::parse($fecha, $calendario->zona());
    }

    public function turnoOperativo(): ?string
    {
        return $this->generado_dispositivo_at
            ? app(CalendarioOperativo::class)->clasificar($this->generado_dispositivo_at)['turno_operativo']
            : null;
    }

    #[Scope]
    protected function deJornada(Builder $consulta, string $fecha, ?string $turno = null): void
    {
        $calendario = app(CalendarioOperativo::class);
        $vigencia = $calendario->primeraVigencia();
        $inicio = CarbonImmutable::parse($fecha, $calendario->zona())->startOfDay();
        $siguiente = $inicio->addDay()->startOfDay();

        $consulta->where(function (Builder $jornada) use ($inicio, $siguiente, $calendario, $vigencia, $fecha, $turno): void {
            $jornada->where(fn (Builder $historica) => $historica
                ->when($vigencia, fn (Builder $q) => $q->where('generado_dispositivo_at', '<', $vigencia))
                ->when($turno, fn (Builder $q) => $q->where('turno', $turno))
                ->where(fn (Builder $dia) => $dia
                    ->where(fn (Builder $noche) => $noche
                        ->where('turno', self::TURNO_NOCHE)
                        ->where('generado_dispositivo_at', '>=', $inicio->setTime(self::HORA_FIN_NOCHE, 0)->utc())
                        ->where('generado_dispositivo_at', '<', $siguiente->setTime(self::HORA_FIN_NOCHE, 0)->utc()))
                    ->orWhere(fn (Builder $otrosTurnos) => $otrosTurnos
                        ->where(fn (Builder $turno) => $turno->where('turno', '!=', self::TURNO_NOCHE)->orWhereNull('turno'))
                        ->where('generado_dispositivo_at', '>=', $inicio->utc())
                        ->where('generado_dispositivo_at', '<', $siguiente->utc()))));

            if ($vigencia) {
                [$desde, $hasta] = ($turno ? $calendario->rangoTurno($fecha, $turno) : null) ?? $calendario->rango($fecha, $fecha);
                $jornada->orWhere(fn (Builder $operativa) => $operativa
                    ->where('generado_dispositivo_at', '>=', $desde->max($vigencia))
                    ->where('generado_dispositivo_at', '<', $hasta));
            }
        });
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
