<?php

namespace App\Services\Materiales;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Support\Str;

class UnidadesMateriales
{
    public const ALIAS = [
        'unidades' => ['unidad', 'un', 'u', 'und', 'uds'],
        'kg' => ['kilo', 'kilos', 'kilogramo', 'kilogramos', 'kgs'],
        'gramos' => ['g', 'gr', 'grs', 'gramo'],
        'litros' => ['l', 'lt', 'lts', 'litro'],
        'metros' => ['m', 'mt', 'mts', 'metro'],
        'cajas' => ['caja'], 'rollos' => ['rollo'], 'bins' => ['bin'], 'pallets' => ['pallet'],
    ];

    public static function clave(string $valor): string
    {
        return mb_strtolower(Str::ascii(trim(preg_replace('/\s+/u', ' ', $valor))));
    }

    public static function canonica(string $valor): string
    {
        $clave = self::clave($valor);
        foreach (self::ALIAS as $codigo => $alias) {
            if ($clave === $codigo || in_array($clave, $alias, true)) {
                return $codigo;
            }
        }

        return $clave;
    }

    /** Cantidades a tres decimales: se rechaza la pérdida de precisión en vez de redondear stock. */
    public static function convertir(string|int|float $cantidad, string $factor): string
    {
        try {
            $entrada = BigDecimal::of((string) $cantidad)->toScale(3, RoundingMode::Unnecessary);
            $multiplicador = BigDecimal::of($factor)->toScale(6, RoundingMode::Unnecessary);
            if ($entrada->isNegative() || ! $multiplicador->isPositive()) {
                throw new DomainException('La cantidad debe ser no negativa y el factor debe ser mayor que cero.');
            }
            $salida = $entrada->multipliedBy($multiplicador)->toScale(3, RoundingMode::Unnecessary);
            if ($salida->isGreaterThan('99999999999.999')) {
                throw new DomainException('La cantidad convertida supera el máximo permitido.');
            }

            return (string) $salida;
        } catch (MathException $error) {
            throw new DomainException('La conversión debe producir una cantidad exacta de hasta tres decimales.', previous: $error);
        }
    }
}
