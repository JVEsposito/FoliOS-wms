<?php

namespace App\Finanzas\Comex;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

final class ValorCosto
{
    public const MONEDAS = ['CLP' => 0, 'USD' => 2, 'EUR' => 2, 'CNY' => 2, 'JPY' => 0, 'GBP' => 2];

    public function convertir(array $d): array
    {
        $decimales = self::MONEDAS[$d['moneda']];
        foreach (['previsto', 'comprometido'] as $k) {
            if (isset($d[$k]) && ! BigDecimal::of($d[$k])->isEqualTo(BigDecimal::of($d[$k])->toScale($decimales, RoundingMode::HalfUp))) {
                throw ValidationException::withMessages([$k => 'Use los decimales de la moneda del costo.']);
            }
        }
        $tasa = $d['moneda'] === 'CLP' ? '1' : $d['tasa_clp'];
        if (! BigDecimal::of($tasa)->isPositive()) {
            throw ValidationException::withMessages(['tasa_clp' => 'La tasa CLP por unidad debe ser positiva.']);
        }
        $clp = fn ($m) => $m === null ? null : (string) BigDecimal::of($m)->multipliedBy($tasa)->toScale(0, RoundingMode::HalfUp);

        return ['tasa_clp' => $tasa, 'fecha' => $d['moneda'] === 'CLP' ? $d['fecha'] : $d['fecha_cambio'], 'fuente' => $d['moneda'] === 'CLP' ? 'CLP nominal' : $d['fuente_cambio'], 'politica' => 'Manual: CLP por unidad; HALF_UP a pesos', 'previsto_clp' => $clp($d['previsto']), 'comprometido_clp' => $clp($d['comprometido'] ?? null)];
    }
}
