<?php

namespace App\Finanzas;

use Carbon\CarbonImmutable;

/**
 * Mes calendario en hora de planta. Las fechas operacionales se comparan como fechas; las
 * horas exactas (inicio y término de una estadía), en UTC, como las guarda la operación.
 */
final class Periodo
{
    private const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    private function __construct(
        public readonly CarbonImmutable $inicio,
        public readonly CarbonImmutable $fin,
    ) {}

    public static function mes(?string $mes = null): self
    {
        $zona = (string) config('app.operational_timezone');
        $inicio = $mes === null
            ? CarbonImmutable::now($zona)->startOfMonth()
            : CarbonImmutable::createFromFormat('!Y-m', $mes, $zona)->startOfMonth();

        return new self($inicio, $inicio->endOfMonth()->startOfDay());
    }

    /** Fecha de hoy en hora de planta, «Y-m-d». */
    public static function hoy(): string
    {
        return CarbonImmutable::now((string) config('app.operational_timezone'))->toDateString();
    }

    public function clave(): string
    {
        return $this->inicio->format('Y-m');
    }

    /** «noviembre de 2026». */
    public function nombre(): string
    {
        return self::MESES[$this->inicio->month - 1].' de '.$this->inicio->year;
    }

    public function siguiente(): self
    {
        return self::mes($this->inicio->addMonthNoOverflow()->format('Y-m'));
    }

    /** ¿Terminó? En hora de planta, desde el primer día del mes siguiente. */
    public function terminado(): bool
    {
        return $this->fin->toDateString() < self::hoy();
    }

    /** Primer instante del mes, en UTC. */
    public function desdeUtc(): string
    {
        return $this->inicio->utc()->format('Y-m-d H:i:s');
    }

    /** Primer instante del mes siguiente, en UTC (límite exclusivo). */
    public function hastaUtc(): string
    {
        return $this->inicio->addMonthNoOverflow()->utc()->format('Y-m-d H:i:s');
    }

    /** @return array{mes: string, inicio: string, fin: string} */
    public function aArreglo(): array
    {
        return ['mes' => $this->clave(), 'inicio' => $this->inicio->toDateString(), 'fin' => $this->fin->toDateString()];
    }
}
