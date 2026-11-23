<?php

namespace App\Services\Asistente\Consultas;

use App\Models\Temporada;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\PeriodoOperativo;
use App\Services\Asistente\TemporadasAsistente;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\CarbonImmutable;

/**
 * Base común de las consultas: temporada, rango y formato de fechas en la zona operativa.
 */
abstract class ConsultaBase implements ConsultaAsistente
{
    public function __construct(
        protected readonly AlcanceOperacionalUsuario $alcance,
        protected readonly ServicioTemporadaActiva $temporadas,
    ) {}

    public function sensible(): bool
    {
        return false;
    }

    public function palabrasSecundarias(): array
    {
        return [];
    }

    public function filtros(): array
    {
        return ['periodo'];
    }

    /** Temporada activa, o la productiva anterior a ella cuando la pregunta lo pide. */
    protected function temporada(ParametrosConsulta $parametros): ?Temporada
    {
        $activa = $this->temporadas->buscar();
        if (! $activa || ! $parametros->temporadaAnterior) {
            return $activa;
        }

        return app(TemporadasAsistente::class)->anterior($activa);
    }

    /**
     * Jornadas consultadas, por ejemplo «22-11-2026», «16-11-2026 al 22-11-2026» o
     * «21-11-2026 (turno B)». Una jornada va de 07:00 a 06:59 del día siguiente.
     */
    protected function periodo(ParametrosConsulta $parametros): string
    {
        [$primera, $ultima] = $parametros->jornadas();
        $inicio = PeriodoOperativo::fechaLegible($primera);
        $texto = $primera === $ultima ? $inicio : $inicio.' al '.PeriodoOperativo::fechaLegible($ultima);

        return $parametros->turno ? "{$texto} (turno {$parametros->turno})" : $texto;
    }

    protected function hora(?CarbonImmutable $momento): ?string
    {
        return $momento?->setTimezone(config('app.operational_timezone'))->format('d-m-Y H:i');
    }

    protected static function minutos(float $minutos): int
    {
        return (int) round($minutos);
    }
}
