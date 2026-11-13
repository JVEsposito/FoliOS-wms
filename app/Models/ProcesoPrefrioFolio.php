<?php

namespace App\Models;

use App\Enums\EstadoFolioProcesoPrefrio;
use App\Enums\EstadoProcesoPrefrio;
use App\Exceptions\ConflictoOperacion;
use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Contracts\PerteneceATemporada;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'proceso_prefrio_id',
    'folio_id',
    'posicion_tunel_prefrio_id',
    'estado',
    'temperatura_inicial',
    'temperatura_final',
    'cargado_at',
    'finalizado_parcial_at',
    'retirado_at',
    'motivo_resultado',
    'observacion',
    'cargado_por_user_id',
    'retirado_por_user_id',
])]
class ProcesoPrefrioFolio extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'procesos_prefrio_folios';

    public function proceso(): BelongsTo
    {
        return $this->belongsTo(ProcesoPrefrio::class, 'proceso_prefrio_id');
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    public function posicion(): BelongsTo
    {
        return $this->belongsTo(PosicionTunelPrefrio::class, 'posicion_tunel_prefrio_id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(EventoPrefrio::class);
    }

    public function cargadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cargado_por_user_id');
    }

    public function retiradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retirado_por_user_id');
    }

    public function ocupaPosicion(): bool
    {
        return $this->retirado_at === null
            && ! in_array($this->estado, [EstadoFolioProcesoPrefrio::Retirado, EstadoFolioProcesoPrefrio::Cancelado], true);
    }

    public function scopeEnTunel(Builder $consulta): Builder
    {
        return $consulta->whereNull('retirado_at')
            ->whereNotIn('estado', [EstadoFolioProcesoPrefrio::Retirado, EstadoFolioProcesoPrefrio::Cancelado]);
    }

    public function scopePendienteRetiroParcial(Builder $consulta): Builder
    {
        return $consulta->whereNotNull('finalizado_parcial_at')->whereNull('retirado_at');
    }

    public function scopeImpideNuevaCarga(Builder $consulta): Builder
    {
        return $consulta->where(fn (Builder $asignacion): Builder => $asignacion
            ->pendienteRetiroParcial()
            ->orWhere(fn (Builder $registro): Builder => $registro
                ->where(fn (Builder $folios): Builder => $folios
                    ->whereNull('finalizado_parcial_at')->orWhereNull('retirado_at'))
                ->whereHas('proceso', fn (Builder $proceso): Builder => $proceso->whereIn('estado', EstadoProcesoPrefrio::activos()))));
    }

    public function scopeEnTunelOperativo(Builder $consulta): Builder
    {
        return $consulta->enTunel()->where(fn (Builder $folios): Builder => $folios
            ->whereNotNull('finalizado_parcial_at')
            ->orWhereHas('proceso', fn (Builder $proceso): Builder => $proceso->whereIn('estado', EstadoProcesoPrefrio::activos())));
    }

    public function scopeConAprobacion(Builder $consulta): Builder
    {
        return $consulta->where('estado', EstadoFolioProcesoPrefrio::Aprobado)
            ->where(fn (Builder $folios): Builder => $folios
                ->whereNotNull('finalizado_parcial_at')
                ->orWhereHas('proceso', fn (Builder $proceso): Builder => $proceso->where('estado', EstadoProcesoPrefrio::Aprobado)));
    }

    public function fechaResultado(): ?DateTimeInterface
    {
        return $this->finalizado_parcial_at ?? $this->proceso?->finalizado_at;
    }

    public function retiroParcialConfirmado(): bool
    {
        return $this->finalizado_parcial_at !== null && $this->retirado_at !== null;
    }

    public function tieneAprobacion(): bool
    {
        return $this->estado === EstadoFolioProcesoPrefrio::Aprobado
            && ($this->finalizado_parcial_at !== null || $this->proceso?->estado === EstadoProcesoPrefrio::Aprobado);
    }

    public function confirmarRetiro(User $usuario, DateTimeInterface $momento): void
    {
        $ocurridoAt = CarbonImmutable::instance($momento)->utc();
        if ($this->finalizado_parcial_at?->greaterThan($ocurridoAt)) {
            throw new ConflictoOperacion('La hora del retiro debe ser igual o posterior a la decisión térmica del folio.');
        }
        $this->update([
            'retirado_at' => $ocurridoAt,
            'retirado_por_user_id' => $usuario->id,
        ]);
    }

    public function confirmarRetiroSiParcial(User $usuario, DateTimeInterface $momento): void
    {
        if ($this->finalizado_parcial_at !== null && $this->retirado_at === null) {
            $this->confirmarRetiro($usuario, $momento);
        }
    }

    public static function confirmarSalidaParcialDeFolio(Folio $folio, User $usuario, DateTimeInterface $momento): void
    {
        $asignacion = $folio->procesosPrefrio()->conAprobacion()->pendienteRetiroParcial()->lockForUpdate()->first();
        $asignacion?->confirmarRetiro($usuario, $momento);
    }

    protected function casts(): array
    {
        return [
            'estado' => EstadoFolioProcesoPrefrio::class,
            'temperatura_inicial' => 'decimal:2',
            'temperatura_final' => 'decimal:2',
            'cargado_at' => 'datetime',
            'finalizado_parcial_at' => 'datetime',
            'retirado_at' => 'datetime',
        ];
    }

    /** La temporada es la del proceso de prefrío. */
    public function temporadaOperacionalId(): ?string
    {
        return $this->proceso?->temporadaOperacionalId();
    }
}
