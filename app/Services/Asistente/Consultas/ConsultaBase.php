<?php

namespace App\Services\Asistente\Consultas;

use App\Models\Temporada;
use App\Services\Asistente\ParametrosConsulta;
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

    /** Temporada activa, o la anterior a ella cuando la pregunta lo pide. */
    protected function temporada(ParametrosConsulta $parametros): ?Temporada
    {
        $activa = $this->temporadas->buscar();
        if (! $activa || ! $parametros->temporadaAnterior) {
            return $activa;
        }

        return Temporada::query()->where('id', '!=', $activa->id)
            ->where('created_at', '<', $activa->created_at)
            ->latest('created_at')->first();
    }

    /** Descripción legible del rango consultado, por ejemplo «22-11-2026» o «16-11-2026 al 22-11-2026». */
    protected function periodo(ParametrosConsulta $parametros): string
    {
        [$desde, $hasta] = $parametros->rango();
        $zona = config('app.operational_timezone');
        $inicio = $desde->setTimezone($zona)->format('d-m-Y');
        $fin = $hasta->setTimezone($zona)->subSecond()->format('d-m-Y');

        return $inicio === $fin ? $inicio : "{$inicio} al {$fin}";
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
