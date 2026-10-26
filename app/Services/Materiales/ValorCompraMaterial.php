<?php

namespace App\Services\Materiales;

use App\Models\DetalleRecepcionMaterial;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class ValorCompraMaterial
{
    public const MONEDAS = ['CLP' => 0, 'USD' => 2, 'EUR' => 2];

    public static function representar(DetalleRecepcionMaterial $detalle): array
    {
        $precio = $detalle->precio_unitario_compra;
        $moneda = $detalle->moneda_compra;

        return [
            'precio_unitario_neto' => $precio,
            'moneda' => $moneda,
            'unidad' => $detalle->unidad_medida,
            'cantidad_aceptada' => $detalle->cantidad_recibida,
            'total_neto' => $precio === null ? null : (string) BigDecimal::of($precio)
                ->multipliedBy($detalle->cantidad_recibida)->toScale(self::MONEDAS[$moneda], RoundingMode::HalfUp),
        ];
    }
}
