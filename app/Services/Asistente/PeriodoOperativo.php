<?php

namespace App\Services\Asistente;

use App\Services\Jornadas\CalendarioOperativo;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Periodos del asistente en jornadas operativas, no en días de calendario: la jornada
 * va de 07:00 a 06:59 del día siguiente y una recepción cuenta en la jornada en que
 * entró el camión. Usa el mismo calendario que Operación ahora, así que las cifras
 * cuadran. Antes de la primera regla publicada se aplican 07:00 y 19:00.
 */
class PeriodoOperativo
{
    private const INICIO_HISTORICO = '07:00';

    private const TURNO_B_HISTORICO = '19:00';

    public function __construct(private readonly CalendarioOperativo $calendario) {}

    /** Jornada (Y-m-d) a la que pertenece un instante; por defecto, ahora. */
    public function jornada(?DateTimeInterface $instante = null): string
    {
        return $this->calendario->fecha($instante ?? CarbonImmutable::now(), inicioHistorico: self::INICIO_HISTORICO);
    }

    /** Turno (A o B) en que cae un instante; por defecto, ahora. */
    public function turno(?DateTimeInterface $instante = null): string
    {
        $momento = CarbonImmutable::instance($instante ?? CarbonImmutable::now());
        [, $cambio] = $this->rangoTurno($this->jornada($momento), 'A');

        return $momento->lt($cambio) ? 'A' : 'B';
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} límites UTC de las jornadas indicadas, el segundo excluido */
    public function rango(string $desde, string $hasta): array
    {
        [$inicio, $fin] = $this->calendario->rango($desde, $hasta, inicioHistorico: self::INICIO_HISTORICO);

        return [$inicio, $fin];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} límites UTC del turno A o B de una jornada */
    public function rangoTurno(string $fecha, string $turno): array
    {
        $rango = $this->calendario->rangoTurno($fecha, $turno);
        if ($rango !== null) {
            return $rango;
        }

        $zona = $this->calendario->zona();
        [$inicio, $fin] = $this->rango($fecha, $fecha);
        $cambio = CarbonImmutable::parse($fecha.' '.self::TURNO_B_HISTORICO, $zona)->utc()->min($fin);

        return $turno === 'A' ? [$inicio, $cambio] : [$cambio, $fin];
    }

    public function sumarDias(string $fecha, int $dias): string
    {
        return CarbonImmutable::parse($fecha, 'UTC')->addDays($dias)->toDateString();
    }

    /** «22-11-2026» */
    public static function fechaLegible(string $fecha): string
    {
        return CarbonImmutable::parse($fecha, 'UTC')->format('d-m-Y');
    }
}
