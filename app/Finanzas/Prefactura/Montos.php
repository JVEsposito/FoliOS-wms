<?php

namespace App\Finanzas\Prefactura;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Cantidades y montos como decimales exactos, con o sin signo; nunca flotantes. */
final class Montos
{
    /** «120.000000» → «120»; «-3.500» → «-3.5»; «0.000» → «0». */
    public static function texto(BigDecimal|string|int $valor): string
    {
        return (string) BigDecimal::of($valor)->strippedOfTrailingZeros();
    }

    /**
     * Un monto con los decimales de su moneda: «95005.000000» → «95005»; «1.5» → «1.50». Los
     * montos ya vienen redondeados; solo si después cambiaron los decimales de la moneda se
     * redondea, la mitad hacia arriba, como la prefactura.
     */
    public static function enMoneda(BigDecimal|string|int $monto, int $decimales): string
    {
        return (string) BigDecimal::of($monto)->toScale($decimales, RoundingMode::HalfUp);
    }

    /**
     * Suma montos por moneda. Cada monto ya viene redondeado a los decimales de su moneda, así
     * que la suma es exacta.
     *
     * @param  iterable<array{moneda: string, simbolo: string, decimales: int, monto: string}>  $montos
     * @return list<array{moneda: string, simbolo: string, decimales: int, monto: string}>
     */
    public static function totales(iterable $montos): array
    {
        $porMoneda = [];
        foreach ($montos as $monto) {
            $actual = $porMoneda[$monto['moneda']] ?? [...$monto, 'monto' => BigDecimal::zero()];
            $actual['monto'] = $actual['monto']->plus($monto['monto']);
            $porMoneda[$monto['moneda']] = $actual;
        }
        ksort($porMoneda);

        return array_values(array_map(fn (array $total): array => [
            'moneda' => $total['moneda'],
            'simbolo' => $total['simbolo'],
            'decimales' => (int) $total['decimales'],
            'monto' => self::enMoneda($total['monto'], (int) $total['decimales']),
        ], $porMoneda));
    }
}
