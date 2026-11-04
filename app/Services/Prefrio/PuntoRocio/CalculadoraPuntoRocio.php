<?php

namespace App\Services\Prefrio\PuntoRocio;

use App\Enums\EstadoMedicionPuntoRocio;
use InvalidArgumentException;

/**
 * Punto de rocío por la aproximación de Magnus (a = 17,625; b = 243,04 °C), la
 * misma de la calculadora de cerezas que usa Calidad. La condensación sobre la
 * fruta depende de su T° superficial, no de la pulpa.
 */
final class CalculadoraPuntoRocio
{
    private const A = 17.625;

    private const B = 243.04;

    public static function puntoRocio(float $temperaturaC, float $humedadRelativa): float
    {
        self::validarHumedad($humedadRelativa);
        $gamma = log($humedadRelativa / 100) + (self::A * $temperaturaC) / (self::B + $temperaturaC);

        return self::B * $gamma / (self::A - $gamma);
    }

    /** Vapor de agua en el aire (g/m³). */
    public static function vapor(float $temperaturaC, float $humedadRelativa): float
    {
        self::validarHumedad($humedadRelativa);

        return 216.7 * ($humedadRelativa / 100 * self::presionSaturacion($temperaturaC)) / (273.15 + $temperaturaC);
    }

    /** Humedad relativa que tendría el mismo aire a otra temperatura, sin deshumidificar. */
    public static function humedadA(float $temperaturaC, float $humedadRelativa, float $temperaturaDestinoC): float
    {
        self::validarHumedad($humedadRelativa);

        return 100 * ($humedadRelativa / 100 * self::presionSaturacion($temperaturaC)) / self::presionSaturacion($temperaturaDestinoC);
    }

    public static function margen(?float $temperaturaSuperficieC, float $puntoRocioC): ?float
    {
        return $temperaturaSuperficieC === null ? null : $temperaturaSuperficieC - $puntoRocioC;
    }

    public static function estado(?float $margenC, float $margenMinimoC): EstadoMedicionPuntoRocio
    {
        return match (true) {
            $margenC === null => EstadoMedicionPuntoRocio::SinSuperficie,
            $margenC < 0 => EstadoMedicionPuntoRocio::CondensacionPosible,
            $margenC < $margenMinimoC => EstadoMedicionPuntoRocio::MargenBajo,
            default => EstadoMedicionPuntoRocio::MargenFavorable,
        };
    }

    private static function presionSaturacion(float $temperaturaC): float
    {
        return 6.1094 * exp(self::A * $temperaturaC / (self::B + $temperaturaC));
    }

    private static function validarHumedad(float $humedadRelativa): void
    {
        if ($humedadRelativa <= 0 || $humedadRelativa > 100) {
            throw new InvalidArgumentException('La humedad relativa debe ser mayor que 0 % y como máximo 100 %.');
        }
    }
}
