<?php

namespace App\Comex\Comercial;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CantidadesComerciales
{
    public const DECIMAL = '/^\d{1,12}(\.\d{1,6})?$/';

    public const MONEDAS = ['CLP' => 0, 'USD' => 2, 'EUR' => 2, 'CNY' => 2, 'JPY' => 0, 'GBP' => 2];

    public function calcular(array $lineas, string $negocio, string $moneda): array
    {
        if (! array_key_exists($moneda, self::MONEDAS)) {
            throw ValidationException::withMessages(['moneda' => 'La moneda no está habilitada para este cálculo.']);
        }
        $total = BigDecimal::zero();
        $pendientes = 0;
        $normalizadas = [];
        foreach ($lineas as $linea) {
            $cantidad = BigDecimal::of($linea['cantidad']);
            if (! $cantidad->isPositive()) {
                throw ValidationException::withMessages(['lineas' => 'Cada línea debe comprometer una cantidad positiva.']);
            }
            $precio = isset($linea['precio']) ? BigDecimal::of($linea['precio']) : null;
            if (($linea['unidad'] ?? null) === 'kg') {
                if (isset($linea['kilos']) && ! $cantidad->isEqualTo($linea['kilos'])) {
                    throw ValidationException::withMessages(['lineas' => 'Si la unidad es kg, los kilos comprometidos deben coincidir con la cantidad.']);
                }
                $linea['kilos'] = (string) $cantidad->strippedOfTrailingZeros();
            }
            if (($negocio === 'firme' && $precio === null) || $precio?->isNegative()) {
                throw ValidationException::withMessages(['lineas' => 'La venta firme exige precio no negativo para cada línea.']);
            }
            $importe = $precio === null ? null : (string) $cantidad->multipliedBy($precio)->toScale(self::MONEDAS[$moneda], RoundingMode::HalfUp);
            $total = $total->plus($importe ?? '0');
            $pendientes += $importe === null ? 1 : 0;
            $normalizadas[] = [...$linea, 'id' => $linea['id'] ?? (string) Str::uuid(), 'cantidad' => (string) $cantidad->strippedOfTrailingZeros(), 'precio' => $precio === null ? null : (string) $precio->strippedOfTrailingZeros(), 'importe' => $importe];
        }

        return ['lineas' => $normalizadas, 'moneda' => $moneda, 'decimales' => self::MONEDAS[$moneda], 'total' => $pendientes ? null : (string) $total->toScale(self::MONEDAS[$moneda]), 'subtotal_conocido' => (string) $total->toScale(self::MONEDAS[$moneda]), 'lineas_sin_precio' => $pendientes];
    }
}
